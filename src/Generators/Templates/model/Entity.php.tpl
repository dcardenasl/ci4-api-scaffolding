<?php

declare(strict_types=1);

namespace {ns};

use {entityBaseFqcn};
{decimalCastUse}
class {resource}Entity extends {entityBaseShort}
{
{castHandlersBlock}    protected $casts = [
{casts}    ];

    protected $dates = {dates};
}
