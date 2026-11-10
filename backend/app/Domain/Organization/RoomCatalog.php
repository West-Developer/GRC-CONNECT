<?php

namespace App\Domain\Organization;

/**
 * Approved room availability used to populate the local scheduling catalog.
 * A room is represented once for every college that may schedule it.
 */
final class RoomCatalog
{
    /**
     * @return list<array{name: string, college: CollegeCode}>
     */
    public static function all(): array
    {
        // Shared campus rooms: LAB 1-4, 2A, 3A-3G, 4A-4E, 5A-5G are a single
        // physical building used by every college, per explicit user scope
        // decision — each is listed for all four colleges rather than the
        // narrower per-college subsets these room codes used to carry. Every
        // other room keeps its prior, narrower college mapping unchanged so
        // no room already holding sections disappears from any picker.
        $allColleges = ['ccs', 'coe', 'coa', 'cbae'];
        $rows = [
            ['2A', $allColleges],
            ['3A', $allColleges], ['3B', $allColleges], ['3C', $allColleges],
            ['3D', $allColleges], ['3E', $allColleges], ['3F', $allColleges],
            ['3G', $allColleges], ['3H', ['coe', 'coa', 'cbae']],
            ['4A', $allColleges], ['4B', $allColleges], ['4C', $allColleges],
            ['4D', $allColleges], ['4E', $allColleges],
            ['4F', ['coe', 'cbae']], ['4G', ['coe', 'cbae']], ['4H', ['coe', 'cbae']],
            ['5A', $allColleges], ['5B', $allColleges], ['5C', $allColleges],
            ['5D', $allColleges], ['5E', $allColleges], ['5F', $allColleges],
            ['5G', $allColleges], ['5H', ['ccs', 'coe']],
            ['COM LAB 2', ['cbae']], ['COM LAB 3', ['cbae']], ['COM LAB 4', ['cbae']],
            ['LAB 1', $allColleges], ['LAB 2', $allColleges], ['LAB 3', $allColleges], ['LAB 4', $allColleges],
            ['EDTECH ROOM', ['coe']], ['PE ROOM', $allColleges], ['PE ROOM 2', ['coe']],
            ['ROOM 1', ['cbae', 'coe']], ['SCI LAB', ['cbae', 'coe', 'coa']],
            ['STUDY HALL', ['cbae', 'coe', 'coa']], ['TESDA HALL', ['ccs']],
        ];

        $catalog = [];
        foreach ($rows as [$name, $colleges]) {
            foreach ($colleges as $college) {
                $catalog[] = ['name' => $name, 'college' => CollegeCode::from($college)];
            }
        }

        return $catalog;
    }

    /**
     * @return list<string>
     */
    public static function forCollege(CollegeCode $college): array
    {
        return array_values(array_map(
            static fn (array $room): string => $room['name'],
            array_filter(self::all(), static fn (array $room): bool => $room['college'] === $college),
        ));
    }
}
