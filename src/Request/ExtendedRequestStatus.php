<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Request;

/**
 * Статус расширенной части заявки (расширенная карточка, `isGov = true`) — объект `gzStatus` в
 * ответе `/requests/{id}/status`. Приходит, пока по заявке идёт работа в ЦЭФ, в том числе когда
 * базовая заявка уже `completed`: карточка опубликована, расширение ещё дорабатывается.
 */
enum ExtendedRequestStatus: string
{
    case Accepted = 'acceptedGz';
    case UnderRevision = 'underRevisionGz';
    case Rejected = 'rejectedGz';
    case Publishing = 'publishingGz';
}
