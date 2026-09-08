<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The number of records returned by an index endpoint by default.
     */
    protected const DEFAULT_PER_PAGE = 15;

    /**
     * The largest page size a client may ask for. High enough for the
     * timetable grid, which renders a whole week in one request: a full
     * curriculum is roughly 430 slots across the sixteen classes, so the
     * ceiling has to clear that or the grid silently loses lessons.
     */
    protected const MAX_PER_PAGE = 600;

    /**
     * Resolve the page size for an index endpoint from the ?per_page= query
     * parameter, falling back to the default and clamping to a sane range.
     */
    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) static::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return static::DEFAULT_PER_PAGE;
        }

        return min($perPage, static::MAX_PER_PAGE);
    }
}
