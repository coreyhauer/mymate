<?php

namespace App\Support;

/**
 * Escape user input that is about to be embedded in a LIKE/ILIKE pattern.
 *
 * Without this, a search box is a pattern box: `%` matches everything and `_` matches any
 * single character, so searching for a literal `_` in a PPPoE username quietly returns the
 * wrong rows, and a lone `%` returns the entire table (a needless full scan on a table this
 * size). Escaping is about correctness first - bindings already handle injection.
 *
 * Note this is for values interpolated INTO a pattern. An operator-supplied pattern that is
 * MEANT to contain wildcards - `mymate.pppoe.name_filter`'s `%pppoe%`, say - must not be
 * passed through here.
 */
class SqlLike
{
    /**
     * Backslash-escape the LIKE metacharacters plus the escape character itself. Postgres uses
     * backslash as the default LIKE escape, so no explicit ESCAPE clause is needed.
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
