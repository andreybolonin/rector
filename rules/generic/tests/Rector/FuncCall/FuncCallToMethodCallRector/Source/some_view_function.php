<?php

declare(strict_types=1);

namespace Rector\Generic\Tests\Rector\FuncCall\FuncCallToMethodCallRector\Source;

if (function_exists('Rector\Generic\Tests\Rector\FuncCall\FuncCallToMethodCallRector\Source\some_view_function')) {
    return;
}

function some_view_function()
{

}
