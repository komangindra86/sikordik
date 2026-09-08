<?php

return [
    'institutions' => [
        'label' => 'Institusi Pendidikan', 'table' => 'institutions',
        'fields' => [
            'code' => ['label' => 'Kode', 'type' => 'text'], 'name' => ['label' => 'Nama institusi', 'type' => 'text'],
            'address' => ['label' => 'Alamat', 'type' => 'textarea'], 'email' => ['label' => 'Email', 'type' => 'email'],
            'phone' => ['label' => 'Nomor telepon', 'type' => 'text'],
        ],
        'rules' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30']],
        'unique' => ['code'],
    ],
    'education-levels' => [
        'label' => 'Jenjang Pendidikan', 'table' => 'education_levels',
        'fields' => ['code' => ['label' => 'Kode', 'type' => 'text'], 'name' => ['label' => 'Nama jenjang', 'type' => 'text'], 'sort_order' => ['label' => 'Urutan', 'type' => 'number']],
        'rules' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'sort_order' => ['required', 'integer', 'min:0', 'max:999']],
        'unique' => ['code'],
    ],
    'study-programs' => [
        'label' => 'Program Studi', 'table' => 'study_programs',
        'fields' => ['institution_id' => ['label' => 'Institusi', 'type' => 'select', 'options' => ['table' => 'institutions']], 'education_level_id' => ['label' => 'Jenjang', 'type' => 'select', 'options' => ['table' => 'education_levels']], 'code' => ['label' => 'Kode', 'type' => 'text'], 'name' => ['label' => 'Nama program studi', 'type' => 'text']],
        'rules' => ['institution_id' => ['required', 'integer', 'exists:institutions,id'], 'education_level_id' => ['required', 'integer', 'exists:education_levels,id'], 'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255']],
        'unique_composite' => ['institution_id', 'code'],
    ],
    'departments' => [
        'label' => 'KSM', 'table' => 'departments',
        'fields' => ['code' => ['label' => 'Kode', 'type' => 'text'], 'name' => ['label' => 'Nama KSM', 'type' => 'text'], 'description' => ['label' => 'Deskripsi', 'type' => 'textarea']],
        'rules' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000']],
        'unique' => ['code'],
    ],
    'clinical-locations' => [
        'label' => 'Lokasi Klinis', 'table' => 'clinical_locations',
        'fields' => ['department_id' => ['label' => 'KSM (opsional)', 'type' => 'select', 'options' => ['table' => 'departments'], 'nullable' => true], 'code' => ['label' => 'Kode', 'type' => 'text'], 'name' => ['label' => 'Nama lokasi', 'type' => 'text'], 'description' => ['label' => 'Deskripsi', 'type' => 'textarea']],
        'rules' => ['department_id' => ['nullable', 'integer', 'exists:departments,id'], 'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000']],
        'unique' => ['code'],
    ],
    'participant-types' => [
        'label' => 'Jenis Peserta', 'table' => 'participant_types',
        'fields' => ['code' => ['label' => 'Kode', 'type' => 'text'], 'name' => ['label' => 'Nama jenis peserta', 'type' => 'text'], 'description' => ['label' => 'Deskripsi', 'type' => 'textarea']],
        'rules' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000']],
        'unique' => ['code'],
    ],
    'educators' => [
        'label' => 'Pembimbing / Penguji / Supervisor', 'table' => 'educators',
        'fields' => ['user_id' => ['label' => 'Akun pengguna (opsional)', 'type' => 'select', 'options' => ['table' => 'users'], 'nullable' => true], 'department_id' => ['label' => 'KSM', 'type' => 'select', 'options' => ['table' => 'departments']], 'employee_number' => ['label' => 'NIP/Nomor pegawai', 'type' => 'text'], 'name' => ['label' => 'Nama lengkap', 'type' => 'text'], 'professional_title' => ['label' => 'Gelar/profesi', 'type' => 'text'], 'email' => ['label' => 'Email', 'type' => 'email'], 'phone' => ['label' => 'Nomor HP', 'type' => 'text'], 'can_mentor' => ['label' => 'Dapat menjadi pembimbing', 'type' => 'checkbox'], 'can_examine' => ['label' => 'Dapat menjadi penguji', 'type' => 'checkbox'], 'can_supervise' => ['label' => 'Dapat menjadi supervisor', 'type' => 'checkbox']],
        'rules' => ['user_id' => ['nullable', 'integer', 'exists:users,id'], 'department_id' => ['required', 'integer', 'exists:departments,id'], 'employee_number' => ['nullable', 'string', 'max:60'], 'name' => ['required', 'string', 'max:255'], 'professional_title' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'can_mentor' => ['nullable', 'boolean'], 'can_examine' => ['nullable', 'boolean'], 'can_supervise' => ['nullable', 'boolean']],
        'unique' => ['user_id', 'employee_number'], 'boolean_fields' => ['can_mentor', 'can_examine', 'can_supervise'],
    ],
];
