<?php

namespace App\Service;

use App\Entity\OpeningHour;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\ClosingException;

class OpeningHourService
{
    protected $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function isOpen(?\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now');
        }

        // filter on day
        $openingHoursEnabledDay = $this->em->getRepository(OpeningHour::class)->findByDay($date, null, true, true);

        // filter on time
        if (count($openingHoursEnabledDay) > 0) {
            $openingHoursEnabledDayTime = array_filter($openingHoursEnabledDay, function ($openingHour) use ($date) {
                $openingHourStart = $openingHour->getStart()->setDate($date->format('Y'), $date->format('m'), $date->format('d'));
                $openingHourEnd = $openingHour->getEnd()->setDate($date->format('Y'), $date->format('m'), $date->format('d'));

                return ($openingHourStart <= $date) && ($openingHourEnd >= $date);
            });

            // final check on closing exceptions
            if (count($openingHoursEnabledDayTime) > 0) {
                $closingExceptions = $this->em->getRepository(ClosingException::class)->findOngoing($date);
                if (!$closingExceptions) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Official opening and closing of the day: the first start and the last end of the opening hours that apply to it.
     * Closing exceptions are not looked at, they are not part of the regular hours.
     *
     * @return null|array{start: \DateTime, end: \DateTime} null when the store is not open that day
     */
    public function getOfficialHours(\DateTime $date): ?array
    {
        $start = null;
        $end = null;
        foreach ($this->em->getRepository(OpeningHour::class)->findByDay($date, null, true, true) as $openingHour) {
            $openingStart = (clone $date)->setTime((int) $openingHour->getStart()->format('G'), (int) $openingHour->getStart()->format('i'));
            $openingEnd = (clone $date)->setTime((int) $openingHour->getEnd()->format('G'), (int) $openingHour->getEnd()->format('i'));
            if (null === $start || $openingStart < $start) {
                $start = $openingStart;
            }
            if (null === $end || $openingEnd > $end) {
                $end = $openingEnd;
            }
        }

        return null === $start ? null : ['start' => $start, 'end' => $end];
    }

    public function isClosed(?\DateTime $date = null)
    {
        return !$this->isOpen($date);
    }
}
