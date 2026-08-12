<?php

namespace Database\Seeders;

use App\Models\BuildPack;
use Illuminate\Database\Seeder;

class BuildPackSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::packs() as $pack) {
            BuildPack::updateOrCreate(['slug' => $pack['slug']], $pack);
        }
    }

    public static function packs(): array
    {
        return [
            ['name'=>'Laravel','slug'=>'laravel','framework'=>'laravel','icon'=>'panels-top-left','accent'=>'#ef4b5f','detectors'=>['composer.json','artisan'],'runtime_versions'=>['8.4','8.3','8.5'],'defaults'=>['package_manager'=>'composer','install_command'=>'composer install --no-dev --optimize-autoloader','build_command'=>'npm run build','start_command'=>'php artisan serve','output_directory'=>null,'container_port'=>8000],'active'=>true],
            ['name'=>'Node.js','slug'=>'nodejs','framework'=>'node','icon'=>'hexagon','accent'=>'#4ea94b','detectors'=>['package.json'],'runtime_versions'=>['24','22','20'],'defaults'=>['package_manager'=>'npm','install_command'=>'npm ci','build_command'=>'npm run build','start_command'=>'npm start','output_directory'=>null,'container_port'=>3000],'active'=>true],
            ['name'=>'Next.js','slug'=>'nextjs','framework'=>'nextjs','icon'=>'triangle','accent'=>'#17152b','detectors'=>['next.config.js','next.config.mjs'],'runtime_versions'=>['24','22','20'],'defaults'=>['package_manager'=>'npm','install_command'=>'npm ci','build_command'=>'npm run build','start_command'=>'npm start','output_directory'=>'.next','container_port'=>3000],'active'=>true],
            ['name'=>'React / Vite','slug'=>'react','framework'=>'react','icon'=>'atom','accent'=>'#4a9cf5','detectors'=>['vite.config.js','vite.config.ts'],'runtime_versions'=>['24','22','20'],'defaults'=>['package_manager'=>'npm','install_command'=>'npm ci','build_command'=>'npm run build','start_command'=>'npm run preview','output_directory'=>'dist','container_port'=>80],'active'=>true],
        ];
    }
}
