<?php

namespace ArtisanSdk\RateLimiter\Tests\Buckets;

use ArtisanSdk\RateLimiter\Buckets\Leaky;
use ArtisanSdk\RateLimiter\Tests\TestCase;
use Carbon\Carbon;

class LeakyTest extends TestCase
{
    /**
     * Test that the bucket, when constructed, is reset.
     */
    public function testConstruct()
    {
        $bucket = new Leaky();
        $this->assertSame('default', $bucket->key(), 'The default key for the bucket should be default.');
        $this->assertSame(60, $bucket->max(), 'The default max for the bucket should be 60.');
        $this->assertSame(1.0, $bucket->rate(), 'The default rate for the bucket should be 1 drip per second.');
        $this->assertSame(0, $bucket->drips(), 'The bucket should be reset to 0 drips when the bucket is created.');

        $bucket = new Leaky('fast', 30, 10);
        $this->assertSame('fast', $bucket->key(), 'The passed key should be set on the bucket.');
        $this->assertSame(30, $bucket->max(), 'The passed max should be set on the bucket.');
        $this->assertSame(10.0, $bucket->rate(), 'The passed rate should be set on the bucket.');
        $this->assertSame(0, $bucket->drips(), 'The bucket should be reset to 0 drips when the bucket is created.');

        $bucket = new Leaky('slow', 30, 0.1);
        $this->assertSame('slow', $bucket->key(), 'The passed key should be set on the bucket.');
        $this->assertSame(30, $bucket->max(), 'The passed max should be set on the bucket.');
        $this->assertSame(0.1, $bucket->rate(), 'The passed rate should be set on the bucket.');
        $this->assertSame(0, $bucket->drips(), 'The bucket should be reset to 0 drips when the bucket is created.');
    }

    /**
     * Test that the bucket can be configured after creation.
     */
    public function testConfigure()
    {
        $settings = [
            'timer' => 1234567890.123,
            'max'   => 10,
            'rate'  => 0.5,
            'drips' => 8,
        ];

        $bucket = (new Leaky())
            ->configure($settings);

        $this->assertSame(
            ['key' => 'default'] + $settings + ['remaining' => 2],
            $bucket->toArray(),
            'Settings should be applied to bucket with drips filling the bucket.'
        );

        $bucket->configure(['key' => 'foo', 'remaining' => 100]);

        $this->assertSame('default', $bucket->key(), 'Key should not be able to be configured.');
        $this->assertSame(2, $bucket->remaining(), 'Remaining should not be able to be configured.');
    }

    /**
     * Test that the bucket can be converted to JSON.
     */
    public function testToJson()
    {
        $bucket = (new Leaky())
            ->timer($time = time());

        $this->assertJson(
            '{"key":"default","timer":'.$time.',"max":60,"rate":1,"drips":0,"remaining":60}',
            $bucket->toJson(),
            'Bucket should be converted to JSON with  "key", "timer", "max", "rate", "drips", and "remaining" keys and values.'
        );
    }

    /**
     * Test that the bucket can leak.
     */
    public function testLeak()
    {
        $time = (float) Carbon::now()->subSeconds(5)->getTimestamp();
        $bucket = (new Leaky())
            ->timer($time)
            ->fill(10);

        $this->assertSame($time, $bucket->timer(), 'The bucket\'s timer should have been set to 5 seconds ago.');
        $this->assertSame(10, $bucket->drips(), 'The bucket should have been filled with 10 drips.');
        $this->assertSame(50, $bucket->remaining(), 'The bucket should have capacity for 50 more drips remaining.');

        $bucket->leak();

        $this->assertGreaterThanOrEqual($time + 5, $bucket->timer(), 'The bucket\'s timer should have been reset when leaked.');
        $this->assertSame(5, $bucket->drips(), 'The bucket was filled with 10 drips and 5 drips should have leaked leaving 5 drips in the bucket.');
        $this->assertSame(55, $bucket->remaining(), 'The bucket had a capacity of 50 drips remaining when filled, 5 drips should have leaked, adding to 55 drip capacity remaining in the bucket.');
    }

    /**
     * Test that the bucket's capacity can be checked.
     */
    public function testCapacity()
    {
        $bucket = new Leaky('foo', 10);

        $bucket = $bucket->fill(10);
        $this->assertTrue($bucket->isFull(), 'The bucket should be full if the max capacity is 10 and it was filled with 10 drips.');

        $bucket = $bucket->reset()->fill(9);
        $this->assertFalse($bucket->isFull(), 'The bucket should not be full if the max capacity is 10 and it was filled with only 9 drips.');

        $bucket = $bucket->reset();
        $this->assertTrue($bucket->isEmpty(), 'The bucket should be empty if the max capacity is 10 and it was not filled with any drips.');

        $bucket = $bucket->reset()->fill(1);
        $this->assertFalse($bucket->isEmpty(), 'The bucket should not be empty if the max capacity is 10 and it was filled with at least 1 drip.');
    }

    /**
     * Test that the bucket's drain time can be calculated.
     */
    public function testDuration()
    {
        $time = microtime(true) - 10;
        $bucket = new Leaky('foo', 50, 0.1);
        $bucket = $bucket->timer($time)->fill(22);

        $this->assertSame(28, $bucket->remaining(), 'Out of a capacity of 50, the bucket was filled with 22 drips, allowing for 28 remaining drips to be added.');
        $this->assertSame(230, (int) $bucket->duration(), 'The drain time for the 22 of 50 drips at 1 leak every 10 seconds (0.1 r/s) should be 240 seconds but 10 seconds have elapsed so it should be 230 seconds.');

        $bucket->leak();
        $this->assertSame(21, $bucket->drips(), 'The bucket should have leaked 1 drip out of the 28 drips leaving 21 drips in the bucket.');
        $this->assertSame(29, $bucket->remaining(), 'The bucket should have leaked 1 drip out of the 28 drips leaving 29 drips of remaining capacity in the bucket.');
        $this->assertSame(210, (int) $bucket->duration(), 'The drain time for the 21 of 50 drips at 1 leak every 10 seconds (0.1 r/s) should be 210 seconds.');
    }
}
