<?php

namespace ArtisanSdk\RateLimiter\Tests;

use ArtisanSdk\RateLimiter\Bucket;
use Carbon\Carbon;

class BucketTest extends TestCase
{
    /**
     * Test that the bucket, when constructed, is reset.
     */
    public function testConstruct()
    {
        $bucket = new Bucket('default');
        $this->assertSame('default', $bucket->key());
        $this->assertSame(60, $bucket->max());
        $this->assertSame(1.0, $bucket->rate());
        $this->assertSame(0, $bucket->drips());

        $bucket = new Bucket('fast', 30, 10);
        $this->assertSame('fast', $bucket->key());
        $this->assertSame(30, $bucket->max());
        $this->assertSame(10.0, $bucket->rate());
        $this->assertSame(0, $bucket->drips());

        $bucket = new Bucket('slow', 30, 0.1);
        $this->assertSame('slow', $bucket->key());
        $this->assertSame(30, $bucket->max());
        $this->assertSame(0.1, $bucket->rate());
        $this->assertSame(0, $bucket->drips());
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

        $bucket = (new Bucket('default'))
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
        $bucket = (new Bucket('default'))
            ->timer($time = time());

        $this->assertJson(
            '{"key":"default","timer":'.$time.',"max":60,"rate":1,"drips":0,"remaining":60}',
            $bucket->toJson(),
            'Bucket should be converted to JSON with a key, timer, max, rate, drips, and remaining keys and values.'
        );
    }

    /**
     * Test that the bucket can leak.
     */
    public function testLeak()
    {
        $time = (float) Carbon::now()->subSeconds(5)->getTimestamp();
        $bucket = (new Bucket('default'))
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
        $bucket = new Bucket('default', 10);

        $bucket = $bucket->fill(10);
        $this->assertTrue($bucket->isFull(), 'The bucket should be full if the max capacity is 10 and it was filled with 10 drips.');

        $bucket = $bucket->reset()->fill(9);
        $this->assertFalse($bucket->isFull(), 'The bucket should not be full if the max capacity is 10 and it was filled with only 9 drips.');

        $bucket = $bucket->reset();
        $this->assertTrue($bucket->isEmpty(), 'The bucket should be empty if the max capacity is 10 and it was not filled with any drips.');

        $bucket = $bucket->reset()->fill(1);
        $this->assertFalse($bucket->isEmpty(), 'The bucket should not be empty if the max capacity is 10 and it was filled with at least 1 drip.');
    }
}
