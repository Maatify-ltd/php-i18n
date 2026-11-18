<?php

declare(strict_types=1);

namespace Maatify\I18n\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\I18n\Enum\I18nErrorCodeEnum;

/**
 * A non-throwing PDO failure during prepare, execute or fetch, or a failed
 * ordering move whose target row still exists. A Persistence Ordering
 * execution failure is retained as the cause. Thrown PDOExceptions propagate
 * unchanged.
 */
final class I18nStorageException extends I18nSystemException
{
    public function __construct(string $operation, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('I18n storage failure (%s).', $operation),
            previous: $previous,
        );
    }

    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return I18nErrorCodeEnum::STORAGE_FAILURE;
    }
}
