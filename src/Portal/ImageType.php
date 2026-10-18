<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Portal;

/**
 * Ракурс фотографии товара в заявке (`imageType` при загрузке и в `imageFiles`). Для расширенной
 * карточки перед модерацией обязательны `front` и `back`.
 */
enum ImageType: string
{
    case Front = 'front';
    case Back = 'back';
    case Left = 'left';
    case Right = 'right';
    case Top = 'top';
    case Bottom = 'bottom';
}
