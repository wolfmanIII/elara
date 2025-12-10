<?php

namespace App\Model\Index;

enum FileIndexStatus: string
{
    case SKIPPED_UNCHANGED   = 'skipped_unchanged';
    case SKIPPED_EXCLUDED    = 'skipped_excluded';
    case INDEXED_OK          = 'indexed_ok';
    case INDEXED_WITH_ERRORS = 'indexed_with_errors';
    case FAILED              = 'failed';
}
