<?php

declare(strict_types=1);
function pythonBaseUrl(): string
{
    return rtrim(
        getenv('BCP_PYTHON_URL') ?: 'http://127.0.0.1:8000',
        '/'
    );
}
