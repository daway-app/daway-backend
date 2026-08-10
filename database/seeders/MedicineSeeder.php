<?php

namespace Database\Seeders;

use App\Models\Medicine;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MedicineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Medicine::create([
            'trade_name' => 'Panadol',
            'active_ingredient' => 'Paracetamol',
            'description' => 'For fever and pain relief.',
            'is_available' => true,
            'stock' => 100,
        ]);

        Medicine::create([
            'trade_name' => 'Amoxil',
            'active_ingredient' => 'Amoxicillin',
            'description' => 'Antibiotic for bacterial infections.',
            'is_available' => true,
            'stock' => 50,
        ]);

        Medicine::create([
            'trade_name' => 'Glucophage',
            'active_ingredient' => 'Metformin',
            'description' => 'For type 2 diabetes.',
            'is_available' => false,
            'stock' => 0,
        ]);
    }
}
