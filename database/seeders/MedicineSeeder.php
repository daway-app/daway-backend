<?php

namespace Database\Seeders;

use App\Models\Medicine;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MedicineSeeder extends Seeder
{
    public function run(): void
    {
        $medicines = [
            [
                'trade_name' => 'Panadol',
                'active_ingredient' => 'Paracetamol',
                'description' => 'For fever and pain relief.',
                'is_available' => true,
                'stock' => 100,
            ],
            [
                'trade_name' => 'Amoxil',
                'active_ingredient' => 'Amoxicillin',
                'description' => 'Antibiotic for bacterial infections.',
                'is_available' => true,
                'stock' => 50,
            ],
            [
                'trade_name' => 'Glucophage',
                'active_ingredient' => 'Metformin',
                'description' => 'For type 2 diabetes.',
                'is_available' => false,
                'stock' => 0,
            ],
            [
                'trade_name' => 'Ventolin',
                'active_ingredient' => 'Salbutamol',
                'description' => 'For asthma and breathing difficulties.',
                'is_available' => true,
                'stock' => 30,
            ],
            [
                'trade_name' => 'Augmentin',
                'active_ingredient' => 'Amoxicillin/Clavulanic acid',
                'description' => 'Antibiotic for bacterial infections.',
                'is_available' => true,
                'stock' => 25,
            ],
        ];

        foreach ($medicines as $medicine) {
            Medicine::updateOrCreate(
                ['trade_name' => $medicine['trade_name']],
                $medicine
            );
        }
    }
}
