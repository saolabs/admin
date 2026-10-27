<?php

namespace Saola\Admin\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Saola\Admin\Resources\Matrix;

class MatrixTest extends TestCase
{
    private function spec(): array
    {
        return ['columns' => ['read' => 'admin.matrix.item', 'create' => ['label' => 'Add']],
            'groups' => [
                ['label' => 'Content', 'rows' => [['label' => 'Articles', 'cells' => ['read' => 'a:read', 'create' => 'a:create']]],
                    'groups' => [['label' => 'Taxonomy', 'rows' => [['label' => 'Tags', 'cells' => ['read' => 't:read']]]]]],
                ['label' => 'System', 'rows' => [['label' => 'Mail', 'cells' => ['read' => 'm:read']]]],
            ]];
    }

    public function test_one_flat_table_with_nested_group_rows_and_fixed_columns(): void
    {
        $matrix = Matrix::normalize($this->spec());
        $this->assertSame(['read', 'create'], array_column($matrix['columns'], 'key'));
        $this->assertSame('admin.matrix.item', $matrix['columns'][0]['labelKey']);
        $this->assertSame('Add', $matrix['columns'][1]['label']);
        $this->assertSame([['group', 0, 'Content'], ['item', 1, 'Articles'], ['group', 1, 'Taxonomy'], ['item', 2, 'Tags'], ['group', 0, 'System'], ['item', 1, 'Mail']],
            array_map(fn ($n) => [$n['kind'], $n['depth'], $n['label']], $matrix['nodes']));
        $this->assertSame(['t:read', null], $matrix['nodes'][3]['cells']);
        $this->assertSame(['a:read', 'a:create', 't:read', 'm:read'], array_keys(Matrix::options($matrix)));
    }

    public function test_projection_aggregates_per_row_column_group_and_whole_matrix(): void
    {
        $view = Matrix::project(Matrix::normalize($this->spec()), ['a:read', 'a:create', 't:read']);
        [$content, $articles, $taxonomy, $tags, $system] = $view['rows'];
        $this->assertTrue($articles['all']); $this->assertTrue($tags['all']); $this->assertTrue($taxonomy['all']); $this->assertTrue($content['all']);
        $this->assertFalse($system['all']); $this->assertFalse($view['all']);
        $this->assertSame(['a:read', 't:read'], $content['cells'][0]['values']);
        $this->assertSame(['a:create'], $content['cells'][1]['values']);
        $this->assertFalse($tags['cells'][1]['enabled']);
        $this->assertSame(['a:read', 't:read', 'm:read'], $view['headers'][0]['values']);
        $this->assertFalse($view['headers'][0]['all']); $this->assertTrue($view['headers'][1]['all']);
        $partial = Matrix::project(Matrix::normalize($this->spec()), ['a:read']);
        $this->assertFalse($partial['rows'][0]['all']); $this->assertFalse($partial['rows'][1]['all']); $this->assertTrue($partial['rows'][0]['cells'][0]['any']);
    }

    public function test_duplicate_cell_values_and_empty_columns_are_rejected(): void
    {
        $this->expectException(\LogicException::class);
        Matrix::normalize(['columns' => ['read' => 'r'], 'groups' => [['label' => 'G', 'rows' => [['label' => 'A', 'cells' => ['read' => 'x']], ['label' => 'B', 'cells' => ['read' => 'x']]]]]]);
    }

    public function test_a_matrix_needs_columns(): void
    {
        $this->expectException(\LogicException::class);
        Matrix::normalize(['columns' => [], 'groups' => []]);
    }
}
