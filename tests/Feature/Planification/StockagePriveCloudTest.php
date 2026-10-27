<?php

namespace Tests\Feature\Planification;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stockage privé sur Laravel Cloud (27.10.2026) : le bucket nommé « prive »
 * remplace ce disque par un disque S3 (R2) ; l'adaptateur S3 doit donc être
 * installé, sinon chaque photo, consentement ou audio échouerait.
 */
class StockagePriveCloudTest extends TestCase
{
    public function test_le_disque_prive_se_construit_sur_un_bucket_s3(): void
    {
        $disque = Storage::build([
            'driver' => 's3', 'key' => 'cle', 'secret' => 'secret', 'region' => 'auto',
            'bucket' => 'prive', 'endpoint' => 'https://exemple.r2.cloudflarestorage.com', 'use_path_style_endpoint' => false,
        ]);

        $this->assertInstanceOf(AwsS3V3Adapter::class, $disque);
    }
}
