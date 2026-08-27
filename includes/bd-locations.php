<?php
/**
 * Bangladesh administrative locations — the 64 districts and the
 * upazilas/thanas inside each, used by the booking form's District and
 * Thana selects.
 *
 * Plain data, not options: this never changes per install, so keeping it
 * in code means no migration, no seeding step, and nothing to get out of
 * sync between the front-end form and the server-side validation that
 * checks what was submitted actually exists (see is_valid_location()).
 *
 * Keys are district names exactly as they are stored on the patient
 * record, so renaming one here would orphan existing records — add
 * rather than rename.
 */

namespace MDBK;

if (!defined('ABSPATH')) exit;

class MDBK_BD_Locations {

    /**
     * district => [thana, ...], grouped by division in source order so the
     * list stays readable, but returned flat and alphabetical.
     *
     * Each district holds BOTH its rural upazilas and, where one exists,
     * its metropolitan police thanas — the two together are what a
     * patient actually needs to find themselves. Upazilas alone left the
     * eight city districts unusable: a Dhaka patient got Savar and
     * Dhamrai but no Uttara, Dhanmondi or Mirpur, which is where most of
     * them live. Dhaka has no "Sadar" upazila of its own for the same
     * reason — the city proper is covered by the DMP thanas listed here,
     * so do not add one back.
     *
     * A name can repeat across districts (Kotwali exists in six of them);
     * that is fine, since a thana is only ever resolved within a chosen
     * district — see is_valid().
     */
    private static $data = [
        // ----- Barishal -----
        'Barguna'          => ['Amtali', 'Bamna', 'Barguna Sadar', 'Betagi', 'Patharghata', 'Taltali'],
        'Barishal'         => ['Agailjhara', 'Airport', 'Babuganj', 'Bakerganj', 'Banaripara', 'Bandar', 'Barishal Sadar', 'Gaurnadi', 'Hizla', 'Kawnia', 'Kotwali', 'Mehendiganj', 'Muladi', 'Wazirpur'],
        'Bhola'            => ['Bhola Sadar', 'Burhanuddin', 'Char Fasson', 'Daulatkhan', 'Lalmohan', 'Manpura', 'Tazumuddin'],
        'Jhalokati'        => ['Jhalokati Sadar', 'Kathalia', 'Nalchity', 'Rajapur'],
        'Patuakhali'       => ['Bauphal', 'Dashmina', 'Dumki', 'Galachipa', 'Kalapara', 'Mirzaganj', 'Patuakhali Sadar', 'Rangabali'],
        'Pirojpur'         => ['Bhandaria', 'Indurkani', 'Kawkhali', 'Mathbaria', 'Nazirpur', 'Nesarabad', 'Pirojpur Sadar'],

        // ----- Chattogram -----
        'Bandarban'        => ['Ali Kadam', 'Bandarban Sadar', 'Lama', 'Naikhongchhari', 'Rowangchhari', 'Ruma', 'Thanchi'],
        'Brahmanbaria'     => ['Akhaura', 'Ashuganj', 'Bancharampur', 'Bijoynagar', 'Brahmanbaria Sadar', 'Kasba', 'Nabinagar', 'Nasirnagar', 'Sarail'],
        'Chandpur'         => ['Chandpur Sadar', 'Faridganj', 'Haimchar', 'Haziganj', 'Kachua', 'Matlab Dakshin', 'Matlab Uttar', 'Shahrasti'],
        'Chattogram'       => ['Akbarshah', 'Anwara', 'Bakalia', 'Bandar', 'Banshkhali', 'Bayezid Bostami', 'Boalkhali', 'Chandanaish', 'Chandgaon', 'Chawkbazar', 'Double Mooring', 'EPZ', 'Fatikchhari', 'Halishahar', 'Hathazari', 'Karnaphuli', 'Khulshi', 'Kotwali', 'Lohagara', 'Mirsharai', 'Pahartali', 'Panchlaish', 'Patenga', 'Patiya', 'Rangunia', 'Raozan', 'Sadarghat', 'Sandwip', 'Satkania', 'Sitakunda'],
        'Cox\'s Bazar'     => ['Chakaria', 'Cox\'s Bazar Sadar', 'Kutubdia', 'Maheshkhali', 'Pekua', 'Ramu', 'Teknaf', 'Ukhia'],
        'Cumilla'          => ['Barura', 'Brahmanpara', 'Burichang', 'Chandina', 'Chauddagram', 'Cumilla Sadar', 'Cumilla Sadar Dakshin', 'Daudkandi', 'Debidwar', 'Homna', 'Laksam', 'Manoharganj', 'Meghna', 'Muradnagar', 'Nangalkot', 'Titas'],
        'Feni'             => ['Chhagalnaiya', 'Daganbhuiyan', 'Feni Sadar', 'Fulgazi', 'Parshuram', 'Sonagazi'],
        'Khagrachhari'     => ['Dighinala', 'Khagrachhari Sadar', 'Lakshmichhari', 'Mahalchhari', 'Manikchhari', 'Matiranga', 'Panchhari', 'Ramgarh'],
        'Lakshmipur'       => ['Kamalnagar', 'Lakshmipur Sadar', 'Raipur', 'Ramganj', 'Ramgati'],
        'Noakhali'         => ['Begumganj', 'Chatkhil', 'Companiganj', 'Hatiya', 'Kabirhat', 'Noakhali Sadar', 'Senbagh', 'Sonaimuri', 'Subarnachar'],
        'Rangamati'        => ['Bagaichhari', 'Barkal', 'Belaichhari', 'Juraichhari', 'Kaptai', 'Kawkhali', 'Langadu', 'Naniarchar', 'Rajasthali', 'Rangamati Sadar'],

        // ----- Dhaka -----
        'Dhaka'            => ['Adabor', 'Badda', 'Banani', 'Bangshal', 'Bhashantek', 'Bhatara', 'Bimanbandar', 'Cantonment', 'Chakbazar', 'Dakshinkhan', 'Darus Salam', 'Demra', 'Dhamrai', 'Dhanmondi', 'Dohar', 'Gendaria', 'Gulshan', 'Hatirjheel', 'Hazaribagh', 'Jatrabari', 'Kadamtali', 'Kafrul', 'Kalabagan', 'Kamrangirchar', 'Keraniganj', 'Khilgaon', 'Khilkhet', 'Kotwali', 'Lalbagh', 'Mirpur', 'Mohammadpur', 'Motijheel', 'Mugda', 'Nawabganj', 'New Market', 'Pallabi', 'Paltan', 'Ramna', 'Rampura', 'Rupnagar', 'Sabujbagh', 'Savar', 'Shah Ali', 'Shahbagh', 'Shahjahanpur', 'Sher-e-Bangla Nagar', 'Shyampur', 'Sutrapur', 'Tejgaon', 'Tejgaon Industrial Area', 'Turag', 'Uttara East', 'Uttara West', 'Uttarkhan', 'Wari'],
        'Faridpur'         => ['Alfadanga', 'Bhanga', 'Boalmari', 'Charbhadrasan', 'Faridpur Sadar', 'Madhukhali', 'Nagarkanda', 'Sadarpur', 'Saltha'],
        'Gazipur'          => ['Basan', 'Gacha', 'Gazipur Sadar', 'Joydebpur', 'Kaliakair', 'Kaliganj', 'Kapasia', 'Kashimpur', 'Konabari', 'Pubail', 'Sreepur', 'Tongi East', 'Tongi West'],
        'Gopalganj'        => ['Gopalganj Sadar', 'Kashiani', 'Kotalipara', 'Muksudpur', 'Tungipara'],
        'Kishoreganj'      => ['Austagram', 'Bajitpur', 'Bhairab', 'Hossainpur', 'Itna', 'Karimganj', 'Katiadi', 'Kishoreganj Sadar', 'Kuliarchar', 'Mithamain', 'Nikli', 'Pakundia', 'Tarail'],
        'Madaripur'        => ['Dasar', 'Kalkini', 'Madaripur Sadar', 'Rajoir', 'Shibchar'],
        'Manikganj'        => ['Daulatpur', 'Ghior', 'Harirampur', 'Manikganj Sadar', 'Saturia', 'Shivalaya', 'Singair'],
        'Munshiganj'       => ['Gazaria', 'Lohajang', 'Munshiganj Sadar', 'Sirajdikhan', 'Sreenagar', 'Tongibari'],
        'Narayanganj'      => ['Araihazar', 'Bandar', 'Fatullah', 'Narayanganj Sadar', 'Rupganj', 'Siddhirganj', 'Sonargaon'],
        'Narsingdi'        => ['Belabo', 'Monohardi', 'Narsingdi Sadar', 'Palash', 'Raipura', 'Shibpur'],
        'Rajbari'          => ['Baliakandi', 'Goalandaghat', 'Kalukhali', 'Pangsha', 'Rajbari Sadar'],
        'Shariatpur'       => ['Bhedarganj', 'Damudya', 'Gosairhat', 'Naria', 'Shariatpur Sadar', 'Zajira'],
        'Tangail'          => ['Basail', 'Bhuapur', 'Delduar', 'Dhanbari', 'Ghatail', 'Gopalpur', 'Kalihati', 'Madhupur', 'Mirzapur', 'Nagarpur', 'Sakhipur', 'Tangail Sadar'],

        // ----- Khulna -----
        'Bagerhat'         => ['Bagerhat Sadar', 'Chitalmari', 'Fakirhat', 'Kachua', 'Mollahat', 'Mongla', 'Morrelganj', 'Rampal', 'Sarankhola'],
        'Chuadanga'        => ['Alamdanga', 'Chuadanga Sadar', 'Damurhuda', 'Jibannagar'],
        'Jashore'          => ['Abhaynagar', 'Bagherpara', 'Chaugachha', 'Jashore Sadar', 'Jhikargachha', 'Keshabpur', 'Manirampur', 'Sharsha'],
        'Jhenaidah'        => ['Harinakunda', 'Jhenaidah Sadar', 'Kaliganj', 'Kotchandpur', 'Maheshpur', 'Shailkupa'],
        'Khulna'           => ['Aronghata', 'Batiaghata', 'Dacope', 'Daulatpur', 'Dighalia', 'Dumuria', 'Harintana', 'Khalishpur', 'Khan Jahan Ali', 'Khulna Sadar', 'Koyra', 'Labanchara', 'Paikgachha', 'Phultala', 'Rupsha', 'Sonadanga', 'Terokhada'],
        'Kushtia'          => ['Bheramara', 'Daulatpur', 'Khoksa', 'Kumarkhali', 'Kushtia Sadar', 'Mirpur'],
        'Magura'           => ['Magura Sadar', 'Mohammadpur', 'Shalikha', 'Sreepur'],
        'Meherpur'         => ['Gangni', 'Meherpur Sadar', 'Mujibnagar'],
        'Narail'           => ['Kalia', 'Lohagara', 'Narail Sadar'],
        'Satkhira'         => ['Assasuni', 'Debhata', 'Kalaroa', 'Kaliganj', 'Satkhira Sadar', 'Shyamnagar', 'Tala'],

        // ----- Mymensingh -----
        'Jamalpur'         => ['Bakshiganj', 'Dewanganj', 'Islampur', 'Jamalpur Sadar', 'Madarganj', 'Melandaha', 'Sarishabari'],
        'Mymensingh'       => ['Bhaluka', 'Dhobaura', 'Fulbaria', 'Gaffargaon', 'Gauripur', 'Haluaghat', 'Ishwarganj', 'Muktagachha', 'Mymensingh Sadar', 'Nandail', 'Phulpur', 'Tara Khanda', 'Trishal'],
        'Netrokona'        => ['Atpara', 'Barhatta', 'Durgapur', 'Kalmakanda', 'Kendua', 'Khaliajuri', 'Madan', 'Mohanganj', 'Netrokona Sadar', 'Purbadhala'],
        'Sherpur'          => ['Jhenaigati', 'Nakla', 'Nalitabari', 'Sherpur Sadar', 'Sreebardi'],

        // ----- Rajshahi -----
        'Bogura'           => ['Adamdighi', 'Bogura Sadar', 'Dhunat', 'Dhupchanchia', 'Gabtali', 'Kahaloo', 'Nandigram', 'Sariakandi', 'Shajahanpur', 'Sherpur', 'Shibganj', 'Sonatala'],
        'Chapai Nawabganj' => ['Bholahat', 'Chapai Nawabganj Sadar', 'Gomastapur', 'Nachole', 'Shibganj'],
        'Joypurhat'        => ['Akkelpur', 'Joypurhat Sadar', 'Kalai', 'Khetlal', 'Panchbibi'],
        'Naogaon'          => ['Atrai', 'Badalgachhi', 'Dhamoirhat', 'Mahadevpur', 'Manda', 'Naogaon Sadar', 'Niamatpur', 'Patnitala', 'Porsha', 'Raninagar', 'Sapahar'],
        'Natore'           => ['Bagatipara', 'Baraigram', 'Gurudaspur', 'Lalpur', 'Naldanga', 'Natore Sadar', 'Singra'],
        'Pabna'            => ['Atgharia', 'Bera', 'Bhangura', 'Chatmohar', 'Faridpur', 'Ishwardi', 'Pabna Sadar', 'Santhia', 'Sujanagar'],
        'Rajshahi'         => ['Airport', 'Bagha', 'Bagmara', 'Belpukur', 'Boalia', 'Chandrima', 'Charghat', 'Damkura', 'Durgapur', 'Godagari', 'Kashiadanga', 'Katakhali', 'Mohanpur', 'Motihar', 'Paba', 'Puthia', 'Rajpara', 'Shah Makhdum', 'Tanore'],
        'Sirajganj'        => ['Belkuchi', 'Chauhali', 'Kamarkhanda', 'Kazipur', 'Raiganj', 'Shahjadpur', 'Sirajganj Sadar', 'Tarash', 'Ullahpara'],

        // ----- Rangpur -----
        'Dinajpur'         => ['Biral', 'Birampur', 'Birganj', 'Bochaganj', 'Chirirbandar', 'Dinajpur Sadar', 'Ghoraghat', 'Hakimpur', 'Kaharole', 'Khansama', 'Nawabganj', 'Parbatipur', 'Phulbari'],
        'Gaibandha'        => ['Gaibandha Sadar', 'Gobindaganj', 'Palashbari', 'Phulchhari', 'Sadullapur', 'Saghata', 'Sundarganj'],
        'Kurigram'         => ['Bhurungamari', 'Char Rajibpur', 'Chilmari', 'Kurigram Sadar', 'Nageshwari', 'Phulbari', 'Rajarhat', 'Raomari', 'Ulipur'],
        'Lalmonirhat'      => ['Aditmari', 'Hatibandha', 'Kaliganj', 'Lalmonirhat Sadar', 'Patgram'],
        'Nilphamari'       => ['Dimla', 'Domar', 'Jaldhaka', 'Kishoreganj', 'Nilphamari Sadar', 'Saidpur'],
        'Panchagarh'       => ['Atwari', 'Boda', 'Debiganj', 'Panchagarh Sadar', 'Tetulia'],
        'Rangpur'          => ['Badarganj', 'Gangachhara', 'Haragachh', 'Kaunia', 'Kotwali', 'Mahiganj', 'Mithapukur', 'Parshuram', 'Pirgachha', 'Pirganj', 'Rangpur Sadar', 'Tajhat', 'Taraganj'],
        'Thakurgaon'       => ['Baliadangi', 'Haripur', 'Pirganj', 'Ranisankail', 'Thakurgaon Sadar'],

        // ----- Sylhet -----
        'Habiganj'         => ['Ajmiriganj', 'Bahubal', 'Baniyachong', 'Chunarughat', 'Habiganj Sadar', 'Lakhai', 'Madhabpur', 'Nabiganj', 'Shayestaganj'],
        'Moulvibazar'      => ['Barlekha', 'Juri', 'Kamalganj', 'Kulaura', 'Moulvibazar Sadar', 'Rajnagar', 'Sreemangal'],
        'Sunamganj'        => ['Bishwamvarpur', 'Chhatak', 'Derai', 'Dharampasha', 'Dowarabazar', 'Jagannathpur', 'Jamalganj', 'Madhyanagar', 'Shantiganj', 'Sullah', 'Sunamganj Sadar', 'Tahirpur'],
        'Sylhet'           => ['Airport', 'Balaganj', 'Beanibazar', 'Bishwanath', 'Companiganj', 'Dakshin Surma', 'Fenchuganj', 'Golapganj', 'Gowainghat', 'Jaintiapur', 'Jalalabad', 'Kanaighat', 'Kotwali', 'Moglabazar', 'Osmani Nagar', 'Shahporan', 'Sylhet Sadar', 'Zakiganj'],
    ];

    /** All 64 district names, alphabetical. */
    public static function districts() {
        $names = array_keys(self::$data);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /** One district's thanas, or [] for a district we don't know. */
    public static function thanas($district) {
        $district = (string) $district;
        return isset(self::$data[$district]) ? self::$data[$district] : [];
    }

    /** The whole map — handed to the browser so the Thana select can
     *  repopulate without a round trip on every district change. */
    public static function all() {
        return self::$data;
    }

    /**
     * Whether a district (and, if given, a thana within it) is real.
     * Submitted values are checked against this rather than trusted:
     * these arrive from a form and the select can be edited client-side
     * like any other input.
     */
    public static function is_valid($district, $thana = '') {
        if ($district === '') return $thana === '';
        if (!isset(self::$data[$district])) return false;
        if ($thana === '') return true;
        return in_array($thana, self::$data[$district], true);
    }

    /**
     * The one-line form written to _mdbk_patient_address, so the booking
     * row, print table and CSV can keep reading a single field. Thana
     * first, district second — narrow to broad, the order a Bangladeshi
     * address is normally written in.
     */
    public static function format_address($district, $thana = '') {
        $district = trim((string) $district);
        $thana    = trim((string) $thana);
        if ($district === '') return '';
        if ($thana === '') return $district;
        return $thana . ', ' . $district;
    }
}
