<?php

namespace App\Console\Commands;

use App\Http\Controllers\ProductController;
use App\Models\ProductImage;
use Illuminate\Console\Command;

class GenerateProductThumbs extends Command
{
    protected $signature = 'products:thumbs {--force : បង្កើតឡើងវិញទោះមានរួចហើយ}';
    protected $description = 'Generate thumbnails for existing product images';

    public function handle(): int
    {
        $done = 0;
        $skipped = 0;
        $failed = 0;

        ProductImage::select('id', 'image')->chunkById(100, function ($images) use (&$done, &$skipped, &$failed) {
            foreach ($images as $img) {
                $thumb = public_path('images/products/thumbs/' . $img->image);

                if (is_file($thumb) && !$this->option('force')) {
                    $skipped++;
                    continue;
                }

                ProductController::makeThumbnail($img->image) ? $done++ : $failed++;
            }
        });

        $this->info("Created: {$done} | Skipped: {$skipped} | Failed/SVG: {$failed}");
        return self::SUCCESS;
    }
}