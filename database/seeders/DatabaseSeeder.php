<?php

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@zby.test'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::ADMIN,
                'phone' => '+998901234567',
            ]
        );

        // 2. Dispatchers
        $dispatcher1 = User::updateOrCreate(
            ['email' => 'dispatcher@zby.test'],
            [
                'name' => 'Sarah Connor (Lead Dispatcher)',
                'password' => Hash::make('password'),
                'role' => UserRole::DISPATCHER,
                'phone' => '+998901112233',
            ]
        );

        $dispatcher2 = User::updateOrCreate(
            ['email' => 'dispatcher2@zby.test'],
            [
                'name' => 'John Miller (Dispatcher)',
                'password' => Hash::make('password'),
                'role' => UserRole::DISPATCHER,
                'phone' => '+998904445566',
            ]
        );

        // 3. Drivers
        $driverUser1 = User::updateOrCreate(
            ['email' => 'driver1@zby.test'],
            [
                'name' => 'Alex Turner',
                'password' => Hash::make('password'),
                'role' => UserRole::DRIVER,
                'phone' => '+998907770001',
            ]
        );
        $profile1 = DriverProfile::updateOrCreate(
            ['user_id' => $driverUser1->id],
            [
                'vehicle_type' => 'Toyota Prius (Hybrid)',
                'license_plate' => '01 A 777 AA',
                'status' => DriverStatus::AVAILABLE,
                'current_lat' => 41.2995,
                'current_lng' => 69.2401,
                'last_active_at' => now(),
            ]
        );

        $driverUser2 = User::updateOrCreate(
            ['email' => 'driver2@zby.test'],
            [
                'name' => 'David Vance',
                'password' => Hash::make('password'),
                'role' => UserRole::DRIVER,
                'phone' => '+998907770002',
            ]
        );
        $profile2 = DriverProfile::updateOrCreate(
            ['user_id' => $driverUser2->id],
            [
                'vehicle_type' => 'Chevrolet Cobalt Sedan',
                'license_plate' => '01 B 123 BB',
                'status' => DriverStatus::AVAILABLE,
                'current_lat' => 41.3111,
                'current_lng' => 69.2797,
                'last_active_at' => now(),
            ]
        );

        $driverUser3 = User::updateOrCreate(
            ['email' => 'driver3@zby.test'],
            [
                'name' => 'Malik Al-Farouq',
                'password' => Hash::make('password'),
                'role' => UserRole::DRIVER,
                'phone' => '+998907770003',
            ]
        );
        $profile3 = DriverProfile::updateOrCreate(
            ['user_id' => $driverUser3->id],
            [
                'vehicle_type' => 'Honda Delivery Moto',
                'license_plate' => '01 M 456 CC',
                'status' => DriverStatus::BUSY,
                'current_lat' => 41.3250,
                'current_lng' => 69.2285,
                'last_active_at' => now(),
            ]
        );

        $driverUser4 = User::updateOrCreate(
            ['email' => 'driver4@zby.test'],
            [
                'name' => 'Elena Rostova',
                'password' => Hash::make('password'),
                'role' => UserRole::DRIVER,
                'phone' => '+998907770004',
            ]
        );
        $profile4 = DriverProfile::updateOrCreate(
            ['user_id' => $driverUser4->id],
            [
                'vehicle_type' => 'Hyundai Elantra',
                'license_plate' => '01 D 890 DD',
                'status' => DriverStatus::OFFLINE,
                'current_lat' => 41.2850,
                'current_lng' => 69.2050,
                'last_active_at' => now()->subHours(4),
            ]
        );

        // 4. Sample Orders
        // Order #101: NEW
        Order::updateOrCreate(
            ['order_number' => 'ORD-101'],
            [
                'customer_name' => 'Tech Hub Uzbekistan',
                'customer_phone' => '+998911112222',
                'pickup_address' => 'Amir Timur Avenue 42, Tashkent',
                'pickup_lat' => 41.3123,
                'pickup_lng' => 69.2787,
                'delivery_address' => 'Navoi Street 12, Tashkent',
                'delivery_lat' => 41.3200,
                'delivery_lng' => 69.2450,
                'total_amount' => 45.00,
                'status' => OrderStatus::NEW,
                'created_by_user_id' => $dispatcher1->id,
                'notes' => 'Fragile server equipment. Handle with care.',
            ]
        );

        // Order #102: PENDING_DISPATCH
        $order102 = Order::updateOrCreate(
            ['order_number' => 'ORD-102'],
            [
                'customer_name' => 'Besh Qozon Pilaf Center',
                'customer_phone' => '+998933334444',
                'pickup_address' => 'Iftikhor Street 1, Tashkent',
                'pickup_lat' => 41.3482,
                'pickup_lng' => 69.2842,
                'delivery_address' => 'Bobur Park Residence 18, Tashkent',
                'delivery_lat' => 41.2925,
                'delivery_lng' => 69.2520,
                'total_amount' => 28.50,
                'status' => OrderStatus::PENDING_DISPATCH,
                'created_by_user_id' => $dispatcher1->id,
                'notes' => 'Hot food delivery. Please keep thermal bag zipped.',
            ]
        );
        OrderStatusHistory::firstOrCreate(
            ['order_id' => $order102->id, 'to_status' => OrderStatus::PENDING_DISPATCH],
            [
                'from_status' => OrderStatus::NEW,
                'changed_by_user_id' => $dispatcher1->id,
                'remarks' => 'Order verified and queued for dispatch',
            ]
        );

        // Order #103: ASSIGNED (Awaiting Driver 1 response)
        $order103 = Order::updateOrCreate(
            ['order_number' => 'ORD-103'],
            [
                'customer_name' => 'Pharmacy Grand',
                'customer_phone' => '+998977778888',
                'pickup_address' => 'Oybek Street 55, Tashkent',
                'pickup_lat' => 41.2960,
                'pickup_lng' => 69.2740,
                'delivery_address' => 'Nukus Street 83, Tashkent',
                'delivery_lat' => 41.2910,
                'delivery_lng' => 69.2680,
                'total_amount' => 15.00,
                'status' => OrderStatus::ASSIGNED,
                'current_driver_id' => $profile1->id,
                'created_by_user_id' => $dispatcher1->id,
                'notes' => 'Urgent medical supplies.',
            ]
        );
        OrderAssignment::firstOrCreate(
            ['order_id' => $order103->id, 'driver_id' => $profile1->id, 'status' => AssignmentStatus::PENDING],
            [
                'dispatcher_id' => $dispatcher1->id,
                'assigned_at' => now(),
            ]
        );

        // Order #104: IN_TRANSIT with Driver 3
        $order104 = Order::updateOrCreate(
            ['order_number' => 'ORD-104'],
            [
                'customer_name' => 'Samarkand Darvoza Mall',
                'customer_phone' => '+998999990000',
                'pickup_address' => 'Qoratosh Street 5A, Tashkent',
                'pickup_lat' => 41.3168,
                'pickup_lng' => 69.2312,
                'delivery_address' => 'Mirzo Ulugbek Ave 15, Tashkent',
                'delivery_lat' => 41.3320,
                'delivery_lng' => 69.3140,
                'total_amount' => 60.00,
                'status' => OrderStatus::IN_TRANSIT,
                'current_driver_id' => $profile3->id,
                'created_by_user_id' => $dispatcher2->id,
                'notes' => 'Electronics package.',
            ]
        );
        OrderAssignment::firstOrCreate(
            ['order_id' => $order104->id, 'driver_id' => $profile3->id, 'status' => AssignmentStatus::ACCEPTED],
            [
                'dispatcher_id' => $dispatcher2->id,
                'assigned_at' => now()->subMinutes(25),
                'responded_at' => now()->subMinutes(23),
            ]
        );
        OrderStatusHistory::firstOrCreate(
            ['order_id' => $order104->id, 'to_status' => OrderStatus::IN_TRANSIT],
            [
                'from_status' => OrderStatus::PICKED_UP,
                'changed_by_user_id' => $driverUser3->id,
                'remarks' => 'Package picked up, driving to destination.',
            ]
        );
    }
}
