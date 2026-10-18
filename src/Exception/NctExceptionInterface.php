<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Exception;

/**
 * Общий маркер всех исключений клиента НКТ: `catch (NctExceptionInterface $e)` ловит любой сбой
 * портала или nct.gov.kz, не различая причину.
 */
interface NctExceptionInterface extends \Throwable
{
}
