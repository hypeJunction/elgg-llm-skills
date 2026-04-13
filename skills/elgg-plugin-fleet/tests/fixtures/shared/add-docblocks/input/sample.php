<?php

function already_documented(int $x): int
{
    /**
     * @param int $x
     * @return int
     */
    return $x + 1;
}

function undocumented_function(string $name, ?int $age = null): bool
{
    return $name !== '' && $age !== null;
}

function undocumented_no_return(array $items): void
{
    foreach ($items as $item) {
        echo $item;
    }
}

class Sample
{
    public int $counted = 0;

    private ?string $label;

    /** @var array<string,mixed> */
    private array $alreadyDocumented = [];

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public function tally(int ...$values): int
    {
        $sum = 0;
        foreach ($values as $v) {
            $sum += $v;
        }
        return $sum;
    }

    public function handle($input): mixed
    {
        return $input;
    }
}
