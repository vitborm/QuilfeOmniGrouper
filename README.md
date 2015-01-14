# Quilfe OmniGrouper
PHP class that allows to group raw table data into a multi-level array

## Usage

An example of usage.

```php
use Quilfe\OmniGrouper\OmniGrouper;

...

$baseAxisType = OmniGrouper::TYPE_STRING;
$tertiaryAxisType = OmniGrouper::TYPE_DATE;

$grouper = new OmniGrouper(
    $baseAxisType,
    $tertiaryAxisType,
    [
        'leftTitle' => 'name',
        'rightTitle' => 'date',
        'valueTitle' => 'money',
    ]
);

$rawData = [
    [
        'name' => 'Alex',
        'money' => 320.5,
        'date' => '2014-01-21',
    ],
    [
        'name' => 'Alex',
        'money' => 122.8,
        'date' => '2014-01-21',
    ],
    [
        'name' => 'Alex',
        'money' => 131.3,
        'date' => '2014-03-22',
    ],
    [
        'name' => 'John',
        'money' => 222,
        'date' => null,
    ],
];
$result = $grouper->group($rawData);
```

OmniGrouper can process numeric data and group it by fields of several built-in types.
Also you could use custom field type specifying a callback to convert its value into a string or a number.
When you group the data by numeric or date field, its values will be automatically splitted into a sections.
