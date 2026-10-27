<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * admin:make:resource Shop/Product -> app/Modules/Shop/Resources/ProductResource.php, fields read from the model's table.
 * Discovered automatically: no config to edit. A project may override the stub at stubs/admin.resource.stub.
 */
final class MakeResourceCommand extends Command
{
    protected $signature = 'admin:make:resource {name : Module/Name, e.g. Shop/Product}
        {--model= : Model class (default App\Modules\<Module>\Models\<Name>)}
        {--ability= : Ability prefix (default admin.<key>)}
        {--lang= : Translation key prefix (default <module>.<key>)}
        {--force : Overwrite an existing file}';
    protected $description = 'Create an admin CRUD Resource whose fields come from the model table';

    private const SKIP = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];

    public function handle(): int
    {
        if (!preg_match('#^([A-Z][A-Za-z0-9]*)/([A-Z][A-Za-z0-9]*)$#', (string) $this->argument('name'), $m)) {
            $this->error('Name must be Module/Name in StudlyCase, e.g. Shop/Product.'); return self::FAILURE;
        }
        [, $module, $name] = $m;
        $namespace = rtrim(\Saola\Admin\Pages\PageDiscovery::appNamespace(), '\\').'\\Modules\\'.$module;
        $model = ltrim($this->option('model') ?: $namespace.'\\Models\\'.$name, '\\');
        if (!is_subclass_of($model, Model::class)) { $this->error("Model $model not found. Create it first (make:module) or pass --model."); return self::FAILURE; }
        $key = Str::kebab(Str::pluralStudly($name));
        $prefix = config('saola-admin.generator', []);
        $lang = $this->option('lang') ?: (($prefix['lang_prefix'] ?? null) !== null ? $prefix['lang_prefix'].'.' : Str::snake($module).'.').str_replace('-', '_', $key);
        $ability = $this->option('ability') ?: ($prefix['ability_prefix'] ?? null ?: 'admin').'.'.$key;
        $path = app_path('Modules/'.$module.'/Resources/'.$name.'Resource.php');
        if (is_file($path) && !$this->option('force')) { $this->error("$path exists (use --force)."); return self::FAILURE; }

        [$fields, $skipped] = $this->fields(app($model)->getTable());
        $columns = array_slice(array_keys(array_filter($fields, fn ($f) => $f['type'] !== 'password' && $f['type'] !== 'textarea')), 0, 3) ?: array_slice(array_keys($fields), 0, 1);
        $searchable = array_values(array_filter($columns, fn ($k) => in_array($fields[$k]['type'], ['text', 'email'], true)));
        $quote = fn (array $keys) => implode(', ', array_map(fn ($k) => "'$k'", $keys));
        $lines = [];
        foreach ($fields as $column => $field) $lines[] = "            '$column' => ".$this->export(['labelKey' => $lang.'.fields.'.$column, ...$field]).',';

        $stub = is_file(base_path('stubs/admin.resource.stub')) ? base_path('stubs/admin.resource.stub') : __DIR__.'/../../stubs/admin.resource.stub';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, strtr(file_get_contents($stub), [
            '{{ namespace }}' => $namespace.'\\Resources', '{{ class }}' => $name.'Resource', '{{ model }}' => $model, '{{ modelBase }}' => class_basename($model),
            '{{ key }}' => $key, '{{ lang }}' => $lang, '{{ ability }}' => $ability, '{{ module }}' => Str::snake($module),
            '{{ fields }}' => implode("\n", $lines), '{{ columns }}' => $quote($columns), '{{ searchable }}' => $quote($searchable),
        ]));
        $this->info('Created '.$path);
        foreach ($skipped as $column => $why) $this->warn("Skipped $column: $why");
        $this->line('Add labels, then run admin:lang:sync:');
        $this->line("  '$lang' => ['title' => '".Str::headline(Str::pluralStudly($name))."', 'fields' => [".implode(', ', array_map(fn ($c) => "'$c' => '".Str::headline($c)."'", array_keys($fields)))."]],");
        return self::SUCCESS;
    }

    /** @return array{0: array<string, array>, 1: array<string, string>} */
    private function fields(string $table): array
    {
        if (!Schema::hasTable($table)) return [['name' => ['type' => 'text', 'required' => true, 'maxLength' => 255]], ['*' => "table $table does not exist yet; edit fields() after the migration"]];
        $fields = []; $skipped = [];
        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name']; $type = strtolower($column['type_name']);
            if (in_array($name, self::SKIP, true)) continue;
            if (str_ends_with($name, '_id')) { $skipped[$name] = 'foreign key; declare a relation field with fieldOptions()'; continue; }
            $required = !$column['nullable'] && $column['default'] === null;
            $field = match (true) {
                $name === 'password' => ['type' => 'password', 'writeOnly' => true, 'optionalOnUpdate' => true, 'maxLength' => 200],
                in_array($type, ['bool', 'boolean'], true) || $column['type'] === 'tinyint(1)' => ['type' => 'boolean'],
                in_array($type, ['int', 'int2', 'int4', 'int8', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'], true) => ['type' => 'integer'],
                in_array($type, ['decimal', 'numeric', 'float', 'float4', 'float8', 'double', 'real'], true) => ['type' => 'number'],
                $type === 'date' => ['type' => 'date'],
                in_array($type, ['datetime', 'timestamp', 'timestamptz'], true) => ['type' => 'datetime'],
                in_array($type, ['time', 'timetz'], true) => ['type' => 'time'],
                in_array($type, ['json', 'jsonb'], true) => ['type' => 'json', 'span' => 'full'],
                in_array($type, ['text', 'mediumtext', 'longtext'], true) => ['type' => 'textarea', 'span' => 'full', 'maxLength' => 65535],
                default => ['type' => $name === 'email' ? 'email' : (str_contains($name, 'url') ? 'url' : 'text'),
                    'maxLength' => preg_match('/\((\d+)\)/', (string) $column['type'], $len) ? (int) $len[1] : 255],
            };
            if ($field['type'] === 'json') $skipped[$name] = 'json field needs an array cast on the model';
            if ($required && $field['type'] !== 'boolean') $field['required'] = true;
            $fields[$name] = $field;
        }
        return [$fields ?: ['name' => ['type' => 'text', 'required' => true, 'maxLength' => 255]], $skipped];
    }

    private function export(array $value): string
    {
        return '['.implode(', ', array_map(fn ($k, $v) => "'$k' => ".var_export($v, true), array_keys($value), $value)).']';
    }
}
