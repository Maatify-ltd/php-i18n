<?php

declare(strict_types=1);

namespace Maatify\I18n\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\I18n\Enum\I18nErrorCodeEnum;

/**
 * A non-throwing PDO failure during prepare, execute or fetch, or a failed
 * ordering move whose target row still exists. Callers must propagate this
 * storage error. Thrown PDOExceptions propagate unchanged.
 */
final class I18nStorageException extends I18nSystemException
{
    public function __construct(string $operation)
    {
        parent::__construct(
            sprintf('I18n storage failure (%s).', $operation),
        );
    }

    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return I18nErrorCodeEnum::STORAGE_FAILURE;
    }
}
