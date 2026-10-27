<?php

declare(strict_types=1);

namespace Saola\Admin\Resources;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Saola\Admin\Pages\ListingQuery;

/** Stateless generic persistence. Scope and domain hooks always come from the registered host definition. */
class EloquentResourceStore implements ResourceStore
{
    private function gate(Request $request) { return Gate::forUser($request->user()); }
    private function authorize(Resource $resource, Request $request): void
    {
        $resource->schema();
        $this->gate($request)->authorize($resource->ability());
        abort_unless($resource->can($request, 'viewAny', $resource->model()), 403);
    }
    private function query(Resource $resource, Request $request): Builder
    {
        $query = $resource->query($request);
        if (get_class($query->getModel()) !== $resource->model()) throw new \LogicException('Resource scope changed the model.');
        // Remove only SoftDeletes before materializing all the other host/global scopes.
        if ($resource->supportsTrash()) $query->withTrashed();
        $query = $query->applyScopes()->withoutGlobalScopes()->withoutEagerLoads();
        $sql = $query->getQuery();
        if ($sql->joins || $sql->groups || $sql->unions || $sql->limit || $sql->offset) throw new \LogicException('Resource scope must constrain rows without joins, grouping or pagination.');
        return $query->select($query->getModel()->qualifyColumn('*'));
    }
    private function state(Resource $resource, Builder $query, string $status): Builder
    {
        if (!$resource->supportsTrash()) { abort_if($status === 'trash', 404); return $query; }
        $column = $query->getModel()->getQualifiedDeletedAtColumn();
        return $status === 'trash' ? $query->whereNotNull($column) : $query->whereNull($column);
    }
    private function record(Resource $resource, Request $request, string $id, bool $lock = false): Model
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $id), 404);
        $query = $this->query($resource, $request); $model = $query->getModel();
        if (in_array($model->getKeyType(), ['int', 'integer'], true)) abort_if(filter_var($id, FILTER_VALIDATE_INT) === false, 404);
        // Use the model's binding validation (including HasUuids/HasUlids),
        // while retaining the host visibility scope and the actual primary key.
        $query = $model->resolveRouteBindingQuery($query, $id, $model->getKeyName());
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }
    private function trashed(Resource $resource, Model $record): bool { return $resource->supportsTrash() && $record->trashed(); }
    private function revision(Resource $resource, Model $record, ?int $sequence = null): string
    {
        $attributes = $record->getRawOriginal(); ksort($attributes);
        $sequence ??= (int) $record->getConnection()->table($this->auditTable())->where('resource', $resource->key())->where('record_key', (string) $record->getKey())->max('sequence');
        return hash_hmac('sha256', json_encode([$resource->key(), $record->getTable(), (string) $record->getKey(), $sequence, $attributes], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
    private function canField(array $field, string $kind, Request $request, Model|string $target): bool
    {
        $ability = $field[$kind.'Ability'] ?? null;
        return $ability === null || $this->gate($request)->allows($ability, $target);
    }
    private function base(Resource $resource, Request $request, string $mode): array
    {
        return ['schema' => 1, 'key' => $resource->key(), 'titleKey' => $resource->titleKey(), 'mode' => $mode,
            'endpoint' => $resource->endpoint(), 'returnUrl' => $resource->path(), 'listUrl' => $this->listUrl($resource, $request),
            'csrfToken' => csrf_token(), 'fields' => [], 'rows' => [], 'columns' => []];
    }
    /** Only carry validated list controls between list/create/edit. Never accept a redirect URL. */
    private function listUrl(Resource $resource, Request $request): string
    {
        $fields = $resource->schema();
        $sorts = array_values(array_filter($resource->sortable(), fn ($key) => !isset($fields[$key]) || $this->canField($fields[$key], 'read', $request, $resource->model())));
        $search = array_values(array_filter($resource->searchable(), fn ($key) => !isset($fields[$key]) || $this->canField($fields[$key], 'read', $request, $resource->model())));
        $query = ListingQuery::fromRequest($request, $sorts ?: [app($resource->model())->getKeyName()], $search);
        $extra = $request->validate(['status' => ['sometimes', Rule::in(['active', 'trash'])], 'locale' => ['sometimes', 'string', 'max:40']]);
        $parameters = array_intersect_key($extra + ['q' => $query->search, 'search_by' => $query->searchBy, 'sort' => $query->sort, 'direction' => $query->direction,
            'per_page' => $query->perPage, 'page' => $query->page], $request->query());
        return $resource->path().($parameters ? '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) : '');
    }
    public function listing(Resource $resource, Request $request): array
    {
        $this->authorize($resource, $request);
        $params = $request->validate(['status' => ['sometimes', Rule::in(['active', 'trash'])], 'locale' => ['sometimes', 'string', 'max:40']]);
        $status = $params['status'] ?? 'active'; $locale = $params['locale'] ?? app()->getLocale();
        $trashView = $resource->supportsTrash() && $resource->can($request, 'viewTrash', $resource->model());
        abort_if($status === 'trash' && !$trashView, 403);
        $fields = $resource->schema(); $target = $resource->model();
        $visible = fn (string $key) => !isset($fields[$key]) || $this->canField($fields[$key], 'read', $request, $target);
        $columns = array_values(array_filter($resource->columns(), fn ($column) => $visible($column['key'])));
        $sorts = array_values(array_filter($resource->sortable(), $visible));
        if (!$sorts) $sorts = [app($target)->getKeyName()];
        $search = array_values(array_filter($resource->searchable(), $visible));
        $query = ListingQuery::fromRequest($request, $sorts, $search);
        $base = $this->query($resource, $request);
        $activeCount = $this->state($resource, clone $base, 'active')->count();
        $trashCount = $trashView ? $this->state($resource, clone $base, 'trash')->count() : null;
        $builder = $this->state($resource, clone $base, $status);
        $searchColumns = $query->searchBy !== '' ? [$query->searchBy] : $search;
        if ($query->search !== '' && $search) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query->search).'%';
            $builder->where(function ($where) use ($searchColumns, $pattern, $builder) {
                foreach ($searchColumns as $key) {
                    $column = $where->getQuery()->getGrammar()->wrap($builder->getModel()->qualifyColumn($key));
                    $where->orWhereRaw("LOWER($column) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                }
            });
        }
        $builder->reorder($builder->getModel()->qualifyColumn($query->sort), $query->direction);
        $builder->orderBy($builder->getModel()->getQualifiedKeyName(), $query->direction);
        $page = $builder->paginate($query->perPage, ['*'], 'page', $query->page);
        // Deleting the final row on a page must reveal the preceding page, not a false empty state.
        if ($page->currentPage() > $page->lastPage()) {
            $page = $builder->paginate($query->perPage, ['*'], 'page', $page->lastPage());
        }
        $context = $this->listUrl($resource, $request);
        if ($request->query('page') !== null && $query->page !== $page->currentPage()) {
            $context = preg_replace('/([?&]page=)[^&]*/', '${1}'.$page->currentPage(), $context);
        }
        $suffix = substr($context, strlen($resource->path()));
        $sequences = $base->getModel()->getConnection()->table($this->auditTable())->where('resource', $resource->key())
            ->whereIn('record_key', array_map(fn ($record) => (string) $record->getKey(), $page->items()))->groupBy('record_key')->selectRaw('record_key, MAX(sequence) as version')->pluck('version', 'record_key');
        $rows = [];
        foreach ($page->items() as $record) {
            $id = (string) $record->getKey(); $values = []; $actions = [];
            foreach ($columns as $column) {
                $value = $resource->columnValue($column['key'], $record, $request);
                if (in_array($column['renderer'] ?? 'text', ['image', 'link'], true)) $value = SafeUrlRule::safe($value);
                elseif (($column['renderer'] ?? '') === 'boolean') $value = $value ? '1' : '0';
                elseif (($column['renderer'] ?? '') === 'number') $value = is_numeric($value) ? number_format((float) $value, $column['decimals'] ?? 2) : '';
                elseif (($column['renderer'] ?? '') === 'date') {
                    try { $value = $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : ''; }
                    catch (\Throwable) { $value = ''; }
                }
                // A project cell view (custom renderer) may take a flat map of scalars, e.g. avatar + name + email from columnValue().
                if (is_array($value) && !in_array($column['renderer'] ?? 'text', Resource::RENDERERS, true)) {
                    foreach ($value as $part) if (!is_scalar($part) && $part !== null) throw new \LogicException('Resource cells must be scalar.');
                    $values[$column['key']] = array_map(fn ($part) => $part === null ? '' : (string) $part, $value);
                    continue;
                }
                if (!is_scalar($value) && $value !== null) throw new \LogicException('Resource cells must be scalar.');
                $values[$column['key']] = $value === null ? '' : (string) $value;
            }
            if (!$this->trashed($resource, $record) && $resource->can($request, 'view', $record)) {
                $actions[] = ['operation' => 'edit', 'labelKey' => $resource->can($request, 'update', $record) ? 'admin.resource.edit' : 'admin.resource.view', 'url' => $resource->path().'/'.rawurlencode($id).'/edit'.$suffix];
            }
            foreach ($status === 'trash' ? ['restore', 'purge'] : ['trash'] as $operation) {
                $policy = ['trash' => 'delete', 'restore' => 'restore', 'purge' => 'forceDelete'][$operation];
                if ($resource->supportsTrash() && ($operation !== 'purge' || $resource->supportsPurge()) && $resource->can($request, $policy, $record)) {
                    $actions[] = ['operation' => $operation, 'labelKey' => 'admin.resource.'.$operation, 'url' => ''];
                }
            }
            $rows[] = ['id' => $id, 'values' => $values, 'revision' => $this->revision($resource, $record, (int) ($sequences[$id] ?? 0)), 'actions' => $actions,
                'selectable' => (bool) array_filter($actions, fn ($action) => in_array($action['operation'], ['trash', 'restore'], true))];
        }
        $url = fn (int $number, array $overrides = []) => $resource->path().'?'.http_build_query(array_replace(['locale' => $locale, 'status' => $status, 'q' => $query->search,
            'search_by' => $query->searchBy, 'sort' => $query->sort, 'direction' => $query->direction, 'per_page' => $query->perPage, 'page' => $number], $overrides), '', '&', PHP_QUERY_RFC3986);
        $sortLabels = array_column($resource->columns(), 'labelKey', 'key');
        foreach ($columns as &$column) {
            if (!in_array($column['renderer'] ?? 'text', Resource::RENDERERS, true)) $column['view'] = \Saola\Admin\Themes\HostViews::name('columns', $column['renderer']);
            $column['sortUrl'] = in_array($column['key'], $sorts, true) ? $url(1, ['sort' => $column['key'], 'direction' => $column['key'] === $query->sort && $query->direction === 'asc' ? 'desc' : 'asc']) : '';
        }
        unset($column);
        $numbers = array_unique([1, ...range(max(1, $page->currentPage() - 2), min($page->lastPage(), $page->currentPage() + 2)), $page->lastPage()]);
        sort($numbers); $pages = []; $previous = 0;
        foreach ($numbers as $number) {
            if ($previous && $number > $previous + 1) $pages[] = ['key' => 'gap-'.$previous, 'label' => '…', 'url' => '', 'current' => false];
            $pages[] = ['key' => 'page-'.$number, 'label' => (string) $number, 'url' => $url($number), 'current' => $number === $page->currentPage()];
            $previous = $number;
        }
        return array_replace($this->base($resource, $request, 'list'), ['rows' => $rows, 'columns' => $columns, 'listUrl' => $context,
            'selectable' => (bool) array_filter($rows, fn ($row) => $row['selectable']),
            'status' => $status, 'trashUrl' => $trashView ? $url(1, ['status' => 'trash']) : '', 'activeUrl' => $url(1, ['status' => 'active']),
            'resetUrl' => $resource->path().'?'.http_build_query(['locale' => $locale, 'status' => $status]),
            'createUrl' => $resource->can($request, 'create', $target) ? $resource->path().'/create'.$suffix : '',
            'counts' => ['active' => $activeCount, 'trash' => $trashCount],
            'listing' => ['action' => $resource->path(), 'locale' => $locale, 'q' => $query->search, 'searchBy' => $query->searchBy, 'sort' => $query->sort, 'direction' => $query->direction,
                'filtered' => $query->search !== '' || $query->searchBy !== '' || $query->sort !== $sorts[0] || $query->direction !== 'asc' || $query->perPage !== 25,
                'searchOptions' => array_map(fn ($key) => ['key' => $key, 'labelKey' => $sortLabels[$key] ?? $fields[$key]['labelKey'] ?? 'admin.users.id'], $search),
                'sortOptions' => array_map(fn ($key) => ['key' => $key, 'labelKey' => $sortLabels[$key] ?? $fields[$key]['labelKey'] ?? 'admin.users.id'], $sorts),
                'perPage' => $query->perPage, 'page' => $page->currentPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(),
                'from' => $page->firstItem() ?? 0, 'to' => $page->lastItem() ?? 0, 'pages' => $pages,
                'previous' => $page->currentPage() > 1 ? $url($page->currentPage() - 1) : '', 'next' => $page->hasMorePages() ? $url($page->currentPage() + 1) : ''],
        ]);
    }

    public function editor(Resource $resource, Request $request, ?string $id = null): array
    {
        $this->authorize($resource, $request);
        $record = $id === null ? null : $this->record($resource, $request, $id);
        if ($record) { abort_if($this->trashed($resource, $record), 404); abort_unless($resource->can($request, 'view', $record), 403); }
        else abort_unless($resource->can($request, 'create', $resource->model()), 403);
        $target = $record ?? $resource->model();
        $canSave = $record ? $resource->can($request, 'update', $record) : true; $fields = [];
        foreach ($resource->schema() as $key => $field) {
            $writeOnly = $field['writeOnly'] ?? false;
            $write = $canSave && !($field['readOnly'] ?? false) && $this->canField($field, 'write', $request, $target);
            if ($writeOnly ? !$write : !$this->canField($field, 'read', $request, $target)) continue;
            $type = $field['type'];
            $value = $writeOnly ? '' : ($record ? (Input::multiple($type) || $type === 'json' ? $record->getAttribute($key) : $record->getRawOriginal($key)) : ($field['default'] ?? (Input::multiple($type) ? [] : '')));
            if ($type === 'boolean') $value = (bool) $value;
            elseif ($type === 'select' && $value !== null) $value = (string) $value;
            elseif (Input::multiple($type)) $value = array_map('strval', is_array($value) ? array_values($value) : []);
            elseif ($type === 'json') $value = $value === '' || $value === null ? '' : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            elseif ($type === 'datetime' && $value) $value = substr(str_replace(' ', 'T', (string) $value), 0, 16);
            elseif ($type === 'time' && $value) $value = substr((string) $value, 0, 5);
            elseif (!is_scalar($value) && $value !== null) throw new \LogicException('Resource fields must be scalar.');
            $matrix = ($field['template'] ?? '') === 'matrix' ? Matrix::normalize($resource->fieldMatrix($key, $request, $record)) : null;
            $options = $matrix ? Input::options(Matrix::options($matrix)) : (in_array($type, ['select', 'multiselect'], true) ? Input::options($resource->fieldOptions($key, $request, $record)) : []);
            $fields[] = ['key' => $key, 'labelKey' => $field['labelKey'], 'type' => $type, 'template' => $field['template'], 'value' => $value ?? '', 'readOnly' => !$write,
                'required' => (bool) ($field['required'] ?? false) && !($record && ($field['optionalOnUpdate'] ?? false)), 'writeOnly' => $writeOnly, 'maxLength' => $field['maxLength'],
                'nullable' => (bool) ($field['nullable'] ?? false), 'helpKey' => $field['helpKey'] ?: ($record && $writeOnly && ($field['optionalOnUpdate'] ?? false) ? 'admin.resource.keep_password' : ''),
                'tagText' => $type === 'tags' ? implode(', ', $value) : '', 'previewUrl' => $type === 'image' ? SafeUrlRule::safe($value) : '',
                'commands' => in_array($type, ['markdown', 'richtext'], true) ? array_map(fn ($command) => ['key' => $command, 'labelKey' => 'admin.input.'.$command], ['bold', 'italic', 'heading', 'list', 'link', 'code']) : [],
                'section' => $field['section'], 'span' => $field['span'], 'view' => $field['view'] ?? '', 'placeholderKey' => $field['placeholderKey'], 'rows' => $field['rows'],
                'min' => $field['min'] ?? '', 'max' => $field['max'] ?? '', 'step' => $field['step'] ?? ($type === 'integer' ? 1 : 'any'),
                'maxItems' => $field['maxItems'], 'itemMaxLength' => $field['itemMaxLength'], 'matrix' => $matrix ? Matrix::project($matrix, is_array($value) ? $value : []) : null,
                'options' => array_map(fn ($option) => $option + ['selected' => is_array($value) ? in_array($option['value'], $value, true) : (string) $value === $option['value']], $options)];
        }
        $groups = array_values(array_filter(array_map(fn ($key, $section) => $section + ['key' => $key, 'fields' => array_values(array_filter($fields, fn ($field) => $field['section'] === $key))], array_keys($resource->sections()), $resource->sections()), fn ($section) => count($section['fields']) > 0));
        return array_replace($this->base($resource, $request, $record ? 'edit' : 'create'), ['id' => $id, 'fields' => $fields, 'canSave' => $canSave, 'groups' => $groups, 'firstSection' => $groups[0]['key'] ?? 'main',
            'formLayout' => $resource->formLayout(), 'sections' => array_values(array_map(fn ($key, $section) => ['key' => $key, 'labelKey' => $section['labelKey'], 'descriptionKey' => $section['descriptionKey'] ?? ''], array_keys($resource->sections()), $resource->sections())),
            'mediaEditor' => config('saola-admin.media.enabled', true) && $this->gate($request)->allows(config('saola-admin.media.abilities.view')) ? app(\Saola\Admin\Http\MediaController::class)->editor($request) : null,
            'revision' => $record ? $this->revision($resource, $record) : '', 'endpoint' => $resource->endpoint().($id === null ? '' : '/'.rawurlencode($id))]);
    }
    private function values(Resource $resource, Request $request, ?Model $record): array
    {
        $input = $request->input('values'); abort_unless(is_array($input), 422);
        $input = $resource->prepareValues($input, $request, $record);
        $schema = $resource->schema(); $target = $record ?? $resource->model(); $rules = [];
        foreach ($input as $key => $value) {
            $field = $schema[$key] ?? null;
            abort_unless($field && !($field['readOnly'] ?? false) && $this->canField($field, 'write', $request, $target)
                && (($field['writeOnly'] ?? false) || $this->canField($field, 'read', $request, $target)), 422);
            if (($field['writeOnly'] ?? false) && ($value === '' || $value === null)) unset($input[$key]);
        }
        $hostRules = $resource->rules($request, $record);
        foreach ($schema as $key => $field) {
            if (($field['readOnly'] ?? false) || !$this->canField($field, 'write', $request, $target)) continue;
            $type = $field['type'];
            $options = ($field['template'] ?? '') === 'matrix' ? Input::options(Matrix::options(Matrix::normalize($resource->fieldMatrix($key, $request, $record))))
                : (in_array($type, ['select', 'multiselect'], true) ? Input::options($resource->fieldOptions($key, $request, $record)) : []);
            $typeRules = Input::rules($field, $options);
            $presence = ($field['required'] ?? false) ? ($record ? ['sometimes', 'required'] : ['required']) : ['sometimes'];
            // Host rules add domain validation; they cannot bypass the input type/allowed options contract.
            $rules[$key] = [...$presence, ...(($field['nullable'] ?? false) ? ['nullable'] : []), ...$typeRules, ...($hostRules[$key] ?? [])];
            if (Input::multiple($type)) $rules[$key.'.*'] = ['string', 'distinct', 'max:'.$field['itemMaxLength'], ...($type === 'multiselect' ? [Rule::in(array_column($options, 'value'))] : [])];
        }
        $validated = app('validator')->make($input, $rules)->validate();
        foreach ($validated as $key => &$value) {
            if ($value === null) continue;
            $type = $schema[$key]['type'];
            if ($type === 'integer') $value = (int) $value;
            elseif ($type === 'number') $value = (float) $value;
            elseif ($type === 'json') $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            elseif ($type === 'richtext') $value = RichText::clean($value);
        }
        unset($value);
        return array_filter($validated, fn ($key) => $schema[$key]['persist'] ?? true, ARRAY_FILTER_USE_KEY);
    }
    private function connection(Resource $resource)
    {
        $connection = app($resource->model())->getConnection();
        if ($connection->getName() !== config('database.default')) throw new \LogicException('The default Resource store requires model and audit on the default connection. Bind a custom ResourceStore for other persistence.');
        return $connection;
    }
    private function auditTable(): string
    {
        $table = config('saola-admin.resources.audit_table', 'saola_resource_events');
        if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) throw new \LogicException('Invalid Resource audit table.');
        return $table;
    }
    private function actor(Request $request): array
    {
        return ['actor_type' => get_class($request->user()), 'actor_key' => (string) $request->user()->getAuthIdentifier()];
    }
    private function audit(Resource $resource, Request $request, string $operation, Model $record, array $keys, ?string $requestId = null, ?string $hash = null): void
    {
        $this->connection($resource)->table($this->auditTable())->insert($this->actor($request) + ['id' => (string) Str::uuid(), 'resource' => $resource->key(),
            'record_key' => (string) $record->getKey(), 'operation' => $operation, 'changed_keys' => json_encode(array_values($keys), JSON_THROW_ON_ERROR),
            'request_id' => $requestId, 'input_hash' => $hash, 'created_at' => now()]);
    }
    public function create(Resource $resource, Request $request): array
    {
        $this->authorize($resource, $request); abort_unless($resource->can($request, 'create', $resource->model()), 403);
        abort_if(array_diff(array_keys($request->json()->all()), ['values', 'requestId']), 422);
        $data = $request->validate(['requestId' => ['required', 'uuid']]);
        // Receipt lookup precedes validation (e.g. unique email is already taken by this same request).
        $receipt = $this->connection($resource)->table($this->auditTable())->where($this->actor($request))->where('resource', $resource->key())->where('request_id', $data['requestId'])->first();
        $input = $request->input('values'); abort_unless(is_array($input), 422); ksort($input);
        $hash = hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), (string) config('app.key'));
        if ($receipt) {
            abort_unless(hash_equals($receipt->input_hash, $hash), 409);
            $record = $this->record($resource, $request, $receipt->record_key);
            abort_unless($resource->can($request, 'view', $record), 403);
            return ['schema' => 1, 'redirect' => $resource->path(), 'id' => (string) $record->getKey(), 'replayed' => true];
        }
        $values = $this->values($resource, $request, null);
        try {
            return $this->connection($resource)->transaction(function () use ($resource, $request, $values, $data, $hash) {
                $record = $resource->newRecord($request);
                if (get_class($record) !== $resource->model() || $record->exists) throw new \LogicException('Resource must create a new model.');
                $resource->beforeMutation('create', $record, $request); $resource->save($record, $values, $request);
                abort_unless($this->query($resource, $request)->whereKey($record->getKey())->exists(), 403);
                $this->audit($resource, $request, 'create', $record, array_keys($values), $data['requestId'], $hash);
                return ['schema' => 1, 'redirect' => $resource->path(), 'id' => (string) $record->getKey(), 'replayed' => false];
            });
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) abort(409, 'Resource conflict.');
            throw $error;
        }
    }
    public function update(Resource $resource, Request $request, string $id): array
    {
        $this->authorize($resource, $request);
        abort_if(array_diff(array_keys($request->json()->all()), ['values', 'revision']), 422);
        $data = $request->validate(['revision' => ['required', 'string', 'size:64']]);
        return $this->connection($resource)->transaction(function () use ($resource, $request, $id, $data) {
            $record = $this->record($resource, $request, $id, true); abort_if($this->trashed($resource, $record), 409);
            abort_unless($resource->can($request, 'update', $record), 403);
            abort_unless(hash_equals($this->revision($resource, $record), $data['revision']), 409);
            $values = $this->values($resource, $request, $record);
            $resource->beforeMutation('update', $record, $request); $resource->save($record, $values, $request);
            $this->audit($resource, $request, 'update', $record, array_keys($values));
            return ['schema' => 1, 'redirect' => $resource->path(), 'id' => $id];
        });
    }
    public function transition(Resource $resource, Request $request, string $operation, array $items): array
    {
        $this->authorize($resource, $request);
        abort_unless($resource->supportsTrash() && in_array($operation, ['trash', 'restore', 'purge'], true), 404);
        abort_if($operation === 'purge' && !$resource->supportsPurge(), 404);
        $data = app('validator')->make(['items' => $items], ['items' => ['required', 'array', 'min:1', 'max:50'], 'items.*' => ['array:id,revision'],
            'items.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,191}$/D', 'distinct'], 'items.*.revision' => ['required', 'string', 'size:64']])->validate();
        return $this->connection($resource)->transaction(function () use ($resource, $request, $operation, $data) {
            $items = $data['items']; usort($items, fn ($a, $b) => strcmp($a['id'], $b['id'])); $records = [];
            foreach ($items as $item) {
                $record = $this->record($resource, $request, $item['id'], true);
                abort_unless($resource->can($request, ['trash' => 'delete', 'restore' => 'restore', 'purge' => 'forceDelete'][$operation], $record), 403);
                abort_unless(hash_equals($this->revision($resource, $record), $item['revision']), 409);
                abort_if($this->trashed($resource, $record) !== ($operation !== 'trash'), 409);
                $records[] = $record;
            }
            foreach ($records as $record) {
                $resource->beforeMutation($operation, $record, $request); $resource->$operation($record, $request);
                $this->audit($resource, $request, $operation, $record, []);
            }
            return ['schema' => 1, 'affected' => count($records)];
        });
    }
}
