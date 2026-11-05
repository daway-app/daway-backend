<?php

namespace App\Services\AccountClosure;

use RuntimeException;

/**
 * يُرمى عندما تمنع التزاماتٌ قائمة إتمام الإغلاق.
 * يحمل قائمة الموانع لتُعاد في الاستجابة (409) بنفس شكل بقية الحراس.
 */
class AccountClosureBlocked extends RuntimeException
{
    /** @param list<array{key: string, label: string, count: int}> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('لا يمكن إتمام إغلاق الحساب لوجود التزامات قائمة.');
    }

    /** @return list<array{key: string, label: string, count: int}> */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
