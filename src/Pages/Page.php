<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

use Illuminate\Http\Request;

interface Page
{
    public function key(): string;
    public function titleKey(): string;
    public function ability(): string;
    /** Returns a versioned, read-only ResourceDocument; never an arbitrary model or executable schema. */
    public function document(Request $request): ResourceDocument;
}
