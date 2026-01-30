<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('abbreviation', 10);
            $table->string('country_code', 2)->default('US');
            $table->timestamps();

            $table->unique(['abbreviation', 'country_code']);
        });

        // Seed US states with IDs matching legacy data
        $this->seedStates();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('states');
    }

    /**
     * Seed US states with IDs matching existing legacy data.
     * Legacy system uses alphabetical order with DC after Delaware
     * and PR after Pennsylvania.
     */
    protected function seedStates(): void
    {
        $states = [
            // IDs match legacy system ordering
            ['id' => 1, 'name' => 'Unknown', 'abbreviation' => 'XX'], // Placeholder
            ['id' => 2, 'name' => 'Alaska', 'abbreviation' => 'AK'],
            ['id' => 3, 'name' => 'Alabama', 'abbreviation' => 'AL'],
            ['id' => 4, 'name' => 'Arizona', 'abbreviation' => 'AZ'],
            ['id' => 5, 'name' => 'Arkansas', 'abbreviation' => 'AR'],
            ['id' => 6, 'name' => 'California', 'abbreviation' => 'CA'],
            ['id' => 7, 'name' => 'Colorado', 'abbreviation' => 'CO'],
            ['id' => 8, 'name' => 'Connecticut', 'abbreviation' => 'CT'],
            ['id' => 9, 'name' => 'Delaware', 'abbreviation' => 'DE'],
            ['id' => 10, 'name' => 'District of Columbia', 'abbreviation' => 'DC'],
            ['id' => 11, 'name' => 'Florida', 'abbreviation' => 'FL'],
            ['id' => 12, 'name' => 'Georgia', 'abbreviation' => 'GA'],
            ['id' => 13, 'name' => 'Hawaii', 'abbreviation' => 'HI'],
            ['id' => 14, 'name' => 'Idaho', 'abbreviation' => 'ID'],
            ['id' => 15, 'name' => 'Illinois', 'abbreviation' => 'IL'],
            ['id' => 16, 'name' => 'Indiana', 'abbreviation' => 'IN'],
            ['id' => 17, 'name' => 'Iowa', 'abbreviation' => 'IA'],
            ['id' => 18, 'name' => 'Kansas', 'abbreviation' => 'KS'],
            ['id' => 19, 'name' => 'Kentucky', 'abbreviation' => 'KY'],
            ['id' => 20, 'name' => 'Louisiana', 'abbreviation' => 'LA'],
            ['id' => 21, 'name' => 'Maine', 'abbreviation' => 'ME'],
            ['id' => 22, 'name' => 'Maryland', 'abbreviation' => 'MD'],
            ['id' => 23, 'name' => 'Massachusetts', 'abbreviation' => 'MA'],
            ['id' => 24, 'name' => 'Michigan', 'abbreviation' => 'MI'],
            ['id' => 25, 'name' => 'Minnesota', 'abbreviation' => 'MN'],
            ['id' => 26, 'name' => 'Mississippi', 'abbreviation' => 'MS'],
            ['id' => 27, 'name' => 'Missouri', 'abbreviation' => 'MO'],
            ['id' => 28, 'name' => 'Montana', 'abbreviation' => 'MT'],
            ['id' => 29, 'name' => 'Nebraska', 'abbreviation' => 'NE'],
            ['id' => 30, 'name' => 'Nevada', 'abbreviation' => 'NV'],
            ['id' => 31, 'name' => 'New Hampshire', 'abbreviation' => 'NH'],
            ['id' => 32, 'name' => 'New Jersey', 'abbreviation' => 'NJ'],
            ['id' => 33, 'name' => 'New Mexico', 'abbreviation' => 'NM'],
            ['id' => 34, 'name' => 'New York', 'abbreviation' => 'NY'],
            ['id' => 35, 'name' => 'North Carolina', 'abbreviation' => 'NC'],
            ['id' => 36, 'name' => 'North Dakota', 'abbreviation' => 'ND'],
            ['id' => 37, 'name' => 'Ohio', 'abbreviation' => 'OH'],
            ['id' => 38, 'name' => 'Oklahoma', 'abbreviation' => 'OK'],
            ['id' => 39, 'name' => 'Oregon', 'abbreviation' => 'OR'],
            ['id' => 40, 'name' => 'Pennsylvania', 'abbreviation' => 'PA'],
            ['id' => 41, 'name' => 'Puerto Rico', 'abbreviation' => 'PR'],
            ['id' => 42, 'name' => 'Rhode Island', 'abbreviation' => 'RI'],
            ['id' => 43, 'name' => 'South Carolina', 'abbreviation' => 'SC'],
            ['id' => 44, 'name' => 'South Dakota', 'abbreviation' => 'SD'],
            ['id' => 45, 'name' => 'Tennessee', 'abbreviation' => 'TN'],
            ['id' => 46, 'name' => 'Texas', 'abbreviation' => 'TX'],
            ['id' => 47, 'name' => 'Utah', 'abbreviation' => 'UT'],
            ['id' => 48, 'name' => 'Vermont', 'abbreviation' => 'VT'],
            ['id' => 49, 'name' => 'Virginia', 'abbreviation' => 'VA'],
            ['id' => 50, 'name' => 'Washington', 'abbreviation' => 'WA'],
            ['id' => 51, 'name' => 'West Virginia', 'abbreviation' => 'WV'],
            ['id' => 52, 'name' => 'Wisconsin', 'abbreviation' => 'WI'],
            ['id' => 53, 'name' => 'Wyoming', 'abbreviation' => 'WY'],
            ['id' => 54, 'name' => 'Virgin Islands', 'abbreviation' => 'VI'],
            ['id' => 55, 'name' => 'Guam', 'abbreviation' => 'GU'],
            ['id' => 56, 'name' => 'American Samoa', 'abbreviation' => 'AS'],
        ];

        $now = now();
        foreach ($states as &$state) {
            $state['country_code'] = 'US';
            $state['created_at'] = $now;
            $state['updated_at'] = $now;
        }

        DB::table('states')->insert($states);
    }
};
