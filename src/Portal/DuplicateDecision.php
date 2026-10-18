<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Portal;

/**
 * Решение по найденным дублям, когда заявка вернулась на доработку с причиной DUPLICATE_REVIEW
 * (`POST …/requests/{id}/duplicate-decision`).
 */
enum DuplicateDecision: string
{
    /** Продолжить модерацию новой карточки. */
    case Continue = 'CONTINUE';

    /** Выбрать один из найденных дублей — вместе с его NTIN. */
    case UseExisting = 'USE_EXISTING';
}
