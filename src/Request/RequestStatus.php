<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Request;

/**
 * Коды статуса базовой заявки, которые отдаёт портал в `/requests/{id}/status`, `status.code` деталей
 * и в фильтре `status` списка заявок. Статусы расширенной части (`gzStatus`) — {@see ExtendedRequestStatus}.
 * `tryFrom()` для неизвестного кода вернёт null — портал может добавить новый статус.
 */
enum RequestStatus: string
{
    case New = 'new';
    case OnModeration = 'onModeration';
    case Accepted = 'accepted';
    case UnderRevision = 'underRevision';
    case Cancelled = 'cancelled';
    case ReadyToPublish = 'readyToPublish';
    case ExistingProductSelected = 'existingProductSelected';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /**
     * Заявка закрыта: карточка создана, выбрана существующая или заявка отклонена — дальше по ней
     * ничего не произойдёт. Отозванную (cancelled) можно обновить и снова отправить.
     */
    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Rejected || $this === self::ExistingProductSelected;
    }

    /** Заявку можно редактировать (`PUT …/requests/{id}`) и отправить на модерацию. */
    public function isEditable(): bool
    {
        return $this === self::New || $this === self::UnderRevision || $this === self::Cancelled;
    }
}
