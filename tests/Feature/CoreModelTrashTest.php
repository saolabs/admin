<?php

namespace Saola\Admin\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Saola\Admin\Resources\Resource;
use Saola\Core\Models\Model;

class HardDeleteNote extends Model { protected $table = 'notes'; }
class SoftDeleteNote extends Model { protected $table = 'notes'; protected $deleteMode = 'soft'; }

/** Core models carry SoftDeletes in their traits; only deleteMode 'soft' turns the trash on. */
class CoreModelTrashTest extends TestCase
{
    private function resource(string $model): Resource
    {
        return new class($model) extends Resource {
            public function __construct(private string $class) {}
            public function key(): string { return 'notes'; }
            public function titleKey(): string { return 'notes'; }
            public function model(): string { return $this->class; }
            public function fields(): array { return []; }
            public function query(Request $request): Builder { return ($this->class)::query(); }
        };
    }

    public function test_trash_follows_the_core_delete_mode(): void
    {
        $this->assertFalse($this->resource(HardDeleteNote::class)->supportsTrash());
        $this->assertTrue($this->resource(SoftDeleteNote::class)->supportsTrash());
    }
}
