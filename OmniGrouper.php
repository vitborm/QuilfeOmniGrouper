<?php
/**
 *  Copyright 2015 Vitaly Bormotov <bormvit@mail.ru>
 *
 *  This file is is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This file is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Lesser General Public License for more details.
 *
 *  You should have received a copy of the GNU Lesser General Public License
 *  along with this file. If not, see <http://www.gnu.org/licenses/>.
**/
namespace Quilfe\OmniGrouper;

class OmniGrouper
{
    const TYPE_STRING = 1;

    const TYPE_INTEGER = 2;

    const TYPE_DATE = 3;
    
    const TYPE_FLOAT = 4;

    const TYPE_BOOLEAN = 5;

    const TYPE_CUSTOM = 6;

    protected $baseType;

    protected $tertiaryType;

    protected $parameters;

    protected $isAvg = false;

    protected $maxSegments = 12;

    protected static $timelineSegments = null;

    protected $numericSegments = [];

    /**
     * Constructor.
     *
     * @param integer      $baseType
     * @param integer|null $tertiaryType  (default: null)
     * @param array        $parameters    (default: [])
     *
     * @throws LogicException
     */
    public function __construct($baseType, $tertiaryType = null, array $parameters = [])
    {
        $defaultParameters = [
            'leftTitle' => 'title',
            'rightTitle' => null,
            'baseCallback' => null,
            'tertiaryCallback' => null,
            'dates' => null,
            'valueTitle' => 'value',
            'countTitle' => null,
            'minPercent' => 0.002,
        ];

        $this->parameters = array_merge($defaultParameters, $parameters);
        $this->baseType = $baseType;
        $this->tertiaryType = $tertiaryType;

        if ($tertiaryType && !$this->parameters['rightTitle']) {
            throw new \LogicException("Parameter `rightTitle` should be specified if you are using tertiary axis.");
        }
    }

    /**
     * Set that numeric (secondary) axis is of average and non-additive value
     */
    public function setIsAverage()
    {
        $this->isAvg = true;
        if (!$this->parameters['countTitle']) {
            throw new \LogicException("Parameter `countTitle` should be specified when calculating average values.");
        }
        if ($this->tertiaryType) {
            throw new \LogicException("You cannot use tertiary axis when calculating average values.");
        }
    }

    /**
     * Set the maximum number of one-level elements in grouping.
     * Rest of elements groups by the 'other' key.
     *
     * @param integer $maxSegments
     */
    public function setMaxSegments($maxSegments)
    {
        $this->maxSegments = $maxSegments;
    }

    /**
     * The main method.
     *
     * Iterate over segments and group by the axis values
     * corresponding to settings. Return grouped data.
     *
     * @param array $segments
     *
     * @throws LogicException
     *
     * @return array
     */
    public function group(array $segments)
    {
        if ($this->isAvg) {
            $result = [
                'other' => [
                    'title'    => 'other',
                    'summ'     => 0,
                    'count'    => 0,
                ],
            ];
            $processedSumms = [
                'summ'  => 0,
                'count' => 0,
            ];
        } else {
            $tertiaryData = [
                'other' => [
                    'title'    => 'other',
                    'val'      => 0,
                ],
            ];
            $result = [
                'other' => [
                    'title'    => 'other',
                    'val'      => 0,
                ],
            ];
            $processedSumms = [
                'val'   => 0,
            ];
            $groupedData = [
                'other' => [
                    'other' => [
                        'title'    => 'other',
                        'val'      => 0,
                    ],
                ],
            ];
        }

        $baseAxisComparator = 'segmentComparator';
        $isBaseAxisTimeline = false;

        $isTertiaryAxisTimeline = false;
        if ($this->tertiaryType) {
            $tertiaryAxisComparator = 'segmentComparator';
        }

        if ($this->isInterval($this->baseType) || $this->isInterval($this->tertiaryType)) {
            if (in_array(static::TYPE_DATE, [$this->baseType, $this->tertiaryType])) {
                $wideDatesArray = [];
                foreach ($segments as $segment) {
                    if ($this->baseType == static::TYPE_DATE) {
                        $wideDatesArray[] = $segment[$this->parameters['leftTitle']];
                        $baseAxisComparator = 'segmentTimelineComparator';
                        $isBaseAxisTimeline = true;
                    }
                    if ($this->tertiaryType == static::TYPE_DATE) {
                        $wideDatesArray[] = $segment[$this->parameters['rightTitle']];
                        $tertiaryAxisComparator = 'segmentTimelineComparator';
                        $isTertiaryAxisTimeline = true;
                    }
                }

                $maxDate = '1234-01-01';
                $minDate = '9999-99-99';
                foreach ($wideDatesArray as $date) {
                    if ($date > $maxDate) {
                        $maxDate = $date;
                    }
                    if ($date < $minDate) {
                        $minDate = $date;
                    }
                }

                if ($maxDate > '1234-01-01') {
                    $maxDate = new \DateTime($maxDate);
                } else {
                    $maxDate = new \DateTime();
                }
                $maxDate->setTime(23, 59, 59);
                if ($minDate < '9999-99-99') {
                    $minDate = new \DateTime($minDate);
                } else {
                    $minDate = new \DateTime();
                }

                $wideDates = [];

                if ($this->parameters['dates']) {
                    if (isset($this->parameters['dates']['start'])) {
                        $wideDates['start']  = clone $this->parameters['dates']['start'];
                        if ($minDate && $minDate < $wideDates['start']) {
                            $wideDates['start'] = $minDate;
                        }
                    }

                    if (isset($this->parameters['dates']['ending'])) {
                        $wideDates['ending'] = clone $this->parameters['dates']['ending'];
                        if ($maxDate && $maxDate > $wideDates['ending']) {
                            $wideDates['ending'] = $maxDate;
                        }
                    }
                } else {
                    $wideDates['start'] = $minDate;
                    $wideDates['ending'] = $maxDate;
                }

                $this->initTimelineSegments($wideDates);
            }

            foreach ([
                'base' => $this->baseType,
                $this->tertiaryType ? 'tertiary' : 'empty' => $this->tertiaryType,
            ] as $axis => $type) {
                if ($axis != 'empty' && in_array(
                    $type,
                    [static::TYPE_INTEGER, static::TYPE_FLOAT]
                )) {
                    $dataIndex = $this->parameters[$axis == 'base' ? 'leftTitle' : 'rightTitle'];
                    if ($axis == 'base') {
                        $baseAxisComparator = 'segmentNumericComparator';
                    } else {
                        $tertiaryAxisComparator = 'segmentNumericComparator';
                    }
                    $values = [];
                    foreach ($segments as $segment) {
                        $values[(string) $segment[$dataIndex]] = [
                            'left'  => (float) $segment[$dataIndex],
                            'right' => (float) $segment[$dataIndex],
                        ];
                    }

                    uasort($values, function ($l, $r) {
                        if ($l['left'] < $r['left']) {
                            return -1;
                        }
                        if ($l['left'] > $r['left']) {
                            return 1;
                        }

                        return 0;
                    });

                    $values = array_values($values);

                    while (($cnt = count($values)) > $this->maxSegments) {
                        $min = $values[1]['right'] - $values[0]['left'];
                        $ind = 0;
                        for ($i = 1; $i < $cnt - 1; $i++) {
                            if (($delta = $values[$i + 1]['right'] - $values[$i]['left']) < $min) {
                                $min = $delta;
                                $ind = $i;
                            }
                        }
                        $values[$ind]['right'] = $values[$ind + 1]['right'];
                        unset($values[$ind + 1]);
                        $values = array_values($values);
                    }
                    $this->numericSegments[$axis] = $values;
                }
            }
        }

        foreach ($segments as $segment) {
            $firstTitle = $this->omniDataSegmentize($segment[$this->parameters['leftTitle']], $this->baseType);
            if (!$firstTitle) {
                continue;
            }

            $this->addOmniDataSegment($segment, $firstTitle, $result);

            if ($this->isAvg) {
                $processedSumms['summ']  += $segment[$this->parameters['valueTitle']];
                $processedSumms['count'] += $segment[$this->parameters['countTitle']];
            } else {
                $processedSumms['val']  += $segment[$this->parameters['valueTitle']];
            }

            if ($this->tertiaryType) {
                $thirdTitle = $this->omniDataSegmentize(
                    $segment[$this->parameters['rightTitle']],
                    $this->tertiaryType,
                    true
                ) ?: 'other';
                $this->addOmniDataSegment($segment, $thirdTitle, $tertiaryData);
                if (!isset($groupedData[$firstTitle])) {
                    $groupedData[$firstTitle] = [
                        'other' => [
                            'title' => 'other',
                            'val'   => 0,
                        ]
                    ];
                }
                $this->addOmniDataSegment($segment, $thirdTitle, $groupedData[$firstTitle]);
            }
        }

        unset($segments);

        uasort($result, [
            $this, $baseAxisComparator
        ]);

        $this->limitOmniData($result, $processedSumms, $this->baseType, $groupedData, (
            $this->tertiaryType ? 'primary': null
        ));

        if (!isset($result['other']['val']) || $result['other']['val'] <= 0) {
            unset($result['other']);
        }

        if ($this->tertiaryType) {
            uasort($tertiaryData, [
                $this, $tertiaryAxisComparator
            ]);

            $this->limitOmniData($tertiaryData, $processedSumms, $this->tertiaryType, $groupedData, 'tertiary');
            if ($tertiaryData['other']['val'] <= 0) {
                unset($tertiaryData['other']);
            }

            $baseData = $result;
            $result = [];
            foreach ($baseData as $title => &$row) {
                $sum = 0;
                $result[$title] = $row;
                $result[$title]['tertiary'] = [];
                foreach ($tertiaryData as $tertiaryTitle => &$val) {
                    if ($tertiaryTitle != 'other') {
                        if ($title != 'other') {
                            if (
                                isset($groupedData[$title][$tertiaryTitle]) &&
                                $groupedData[$title][$tertiaryTitle]['val'] > 0
                            ) {
                                $result[$title]['tertiary'][$tertiaryTitle] = $groupedData[$title][$tertiaryTitle];
                                $sum += $groupedData[$title][$tertiaryTitle]['val'];
                            }
                        } else {
                            $otherVal = 0;
                            foreach ($groupedData as $firstKey => &$unparsedRow) {
                                if (
                                    ($firstKey == 'other' || !isset($baseData[$firstKey])) &&
                                    isset($unparsedRow[$tertiaryTitle]) &&
                                    $unparsedRow[$tertiaryTitle]['val'] > 0
                                ) {
                                    $otherVal += $unparsedRow[$tertiaryTitle]['val'];
                                }
                            }
                            $result['other']['tertiary'][$tertiaryTitle] = [
                                'title' => $tertiaryTitle,
                                'val'   => $otherVal,
                            ];
                            $sum += $otherVal;
                        }
                    }
                }
                $rest = $row['val'] - $sum;
                if ($rest > 0) {
                    $result[$title]['tertiary']['other'] = [
                        'title' => 'other',
                        'val'   => $rest,
                    ];
                }
                uasort($result[$title]['tertiary'], [
                    $this, $tertiaryAxisComparator
                ]);
            }
        }

        return [
            'data' => $result,
            'processedSumms' => $processedSumms,
            'isBaseAxisTimeline' => $isBaseAxisTimeline,
            'isTertiaryAxisTimeline' => $isTertiaryAxisTimeline,
            'tertiaryData' => isset($tertiaryData) ? $tertiaryData : null,
        ];
    }

    protected function limitOmniData(
        &$result,
        &$processedSumms,
        $type,
        &$groupedData,
        $mode = null
    ) {
        $i = 0;
        foreach ($result as $key => &$row) {
            if ($key != 'other' && (
                ($type != static::TYPE_DATE && $i >= $this->maxSegments) ||
                $row['val'] <= 0 ||
                (!$this->isAvg && $row['val'] / $processedSumms['val'] < $this->parameters['minPercent'])
            )) {
                if ($this->isAvg) {
                    $result['other']['count'] += $row['count'];
                    $result['other']['summ']  += $row['summ'];
                    $result['other']['val'] = $result['other']['summ'] / $result['other']['count'];
                } else {
                    $result['other']['val'] += $row['val'];
                }

                if ($mode == 'primary') {
                    foreach ($row['tertiary'] as $colTitle => $col) {
                        $groupedData['other'][$colTitle]['val'] += $row['val'];
                    }
                    unset($groupedData[$key]);
                } elseif ($mode == 'tertiary') {
                    foreach ($groupedData as $groupedRow) {
                        if (isset($groupedRow[$key])) {
                            $groupedRow['other']['val'] += $groupedRow[$key]['val'];
                            unset($groupedRow[$key]);
                        }
                    }
                }

                unset($result[$key]);
            } else {
                $i++;
            }
        }
    }

    protected function addOmniDataSegment($segment, $title, &$result)
    {
        if (!isset($result[$title])) {
            $result[$title] = $this->isAvg ? [
                'title'    => $title,
                'summ'     => $segment[$this->parameters['valueTitle']],
                'count'    => $segment[$this->parameters['countTitle']],
                'val'      => $segment[$this->parameters['valueTitle']] / $segment[$this->parameters['countTitle']],
            ] : [
                'title'    => $title,
                'val'      => $segment[$this->parameters['valueTitle']],
                'tertiary' => [
                    'other' => [
                        'title' => 'other',
                        'val'   => 0,
                    ],
                ],
            ];
        } else {
            if ($this->isAvg) {
                $result[$title]['count'] += $segment[$this->parameters['countTitle']];
                $result[$title]['summ']  += $segment[$this->parameters['valueTitle']];
                $result[$title]['val'] =
                    $result[$title]['summ'] / $result[$title]['count'];
            } else {
                $result[$title]['val'] += $segment[$this->parameters['valueTitle']];
            }
        }
    }

    protected function isInterval($type)
    {
        return in_array($type, [static::TYPE_DATE, static::TYPE_INTEGER, static::TYPE_FLOAT]);
    }

    /**
     * Set key for some data segment.
     * If there is timeline of numeric axis,
     * current interval will be splitted by sections.
     */
    protected function omniDataSegmentize($data, $type, $isTertiary = false)
    {
        if (!$data) {
            return false;
        }

        if ($type == static::TYPE_STRING) {
            $data = str_replace("\n", ' ', $data);
            $data = str_replace("\r", ' ', $data);
            $data = trim($data);
            if ($data === 'true') {
                $data = 'Yes';
            } elseif ($data === 'false') {
                $data = 'No';
            }
        } elseif ($type == static::TYPE_BOOLEAN) {
            $data = $data ? 'Yes' : 'No';
        } elseif (in_array($type, [static::TYPE_INTEGER, static::TYPE_FLOAT])) {
            if (is_string($data)) {
                $data = str_replace(' ', '', $data);
                $data = str_replace(',', '.', $data);
            }

            $data = (float) $data;
            foreach ($this->numericSegments[$isTertiary ? 'tertiary' : 'base'] as $segment) {
                if ($segment['right'] >= $data && $segment['left'] <= $data) {
                    $data = ($segment['left'] == $segment['right'])
                        ? $segment['left']
                        : $segment['left']. '...' . $segment['right'];
                    break;
                }
            }
            $data = (string) $data;
        } elseif (static::TYPE_DATE == $type) {
            $data = new \DateTime($data);

            if (self::$timelineSegments['years']) { // years
                $data = $data->format('Y');
            } elseif (!self::$timelineSegments['intervalize']) { // months
                $data = (
                    self::$timelineSegments['ending']->format('Y') ===
                    self::$timelineSegments['start']->format('Y')
                ) ?
                    $data->format('m') :
                    $data->format('Y.m');
            } elseif (self::$timelineSegments['intervalize'] == 'weeks') { // weeks
                $indexDay = (int) date('w', $data->getTimestamp());
                $mondayOffset = ($indexDay + 6) % 7;
                $sundayOffset = (7 - $indexDay) % 7;

                $startCurrentInterval = clone $data;
                $endCurrentInterval   = clone $data;
                $startCurrentInterval->sub(new \DateInterval('P' . $mondayOffset . 'D'));
                $endCurrentInterval->add(new \DateInterval('P' . $sundayOffset . 'D'));
                if ($startCurrentInterval < self::$timelineSegments['start']) {
                    $startCurrentInterval = clone self::$timelineSegments['start'];
                }
                if ($endCurrentInterval > self::$timelineSegments['ending']) {
                    $endCurrentInterval = clone self::$timelineSegments['ending'];
                }

                $format = (
                    self::$timelineSegments['ending']->format('Y') !==
                    self::$timelineSegments['start']->format('Y')
                ) ? 'Y-m-d' : 'm-d';

                $startString = $startCurrentInterval->format($format);
                $endString   = $endCurrentInterval->format($format);

                $data = ($startString === $endString) ? $startString : (
                    (
                        $startCurrentInterval->format('m') === $endCurrentInterval->format('m')
                        && self::$timelineSegments['ending']->format('Y') ===
                        self::$timelineSegments['start']->format('Y')
                    )
                    ? $startCurrentInterval->format('m-d') . '/' . $endCurrentInterval->format('d')
                    : ($startString . '/' . $endString)
                );
            } else {
                $format = (
                    self::$timelineSegments['ending']->format('Y') !== self::$timelineSegments['start']->format('Y')
                ) ? 'Y-m-d' : 'm-d';
                $data = $data->format($format);
            }
        } elseif (static::TYPE_CUSTOM == $type) {
            $callback = $this->parameters[$isTertiary ? 'tertiaryCallback' : 'baseCallback'];
            $function = $callback['callback'];
            $data = $function($data, $callback['context']);
        } else {
            throw new \LogicException("Type {$type} doesn't exists in this class.");
        }

        return $data;
    }

    protected function initTimelineSegments($dates)
    {
        $diff = $dates['ending']->getTimestamp() - $dates['start']->getTimestamp();
        $day = 3600 * 24;
        $month = 30 * $day;
        $year = 365 * $day;

        self::$timelineSegments = [
            'start' => $dates['start'],
            'ending' => $dates['ending'],
            'intervalize' => false,
            'years' => ($diff > 2*$year),
        ];

        if ($diff <= 2*$month) {
            $days = date_diff(
                new \DateTime($dates['start']->format('Y-m-d')),
                new \DateTime($dates['ending']->format('Y-m-d'))
            )->days + 1;

            self::$timelineSegments['intervalize'] = $days <= $this->maxSegments ? 'days' : 'weeks';
        }
    }

    protected function segmentComparator($l, $r)
    {
        if ($l['title'] == 'other') {
            return 1;
        }

        if ($r['title'] == 'other') {
            return -1;
        }

        if ($l['val'] < $r['val']) {
            return 1;
        }

        if ($l['val'] > $r['val']) {
            return -1;
        }

        return strcmp($l['title'], $r['title']);
    }

    protected function segmentTimelineComparator($l, $r)
    {
        return strcmp($l['title'], $r['title']);
    }

    protected function segmentNumericComparator($l, $r)
    {
        if ($l['title'] == 'other') {
            return 1;
        }

        if ($r['title'] == 'other') {
            return -1;
        }

        $left  = (float) (($ind = strpos($l['title'], '.')) ? substr($l['title'], 0, $ind) : $l['title']);
        $right = (float) (($ind = strpos($r['title'], '.')) ? substr($r['title'], 0, $ind) : $r['title']);

        if ($left < $right) {
            return -1;
        }
        if ($left > $right) {
            return 1;
        }

        return 0;
    }
}
