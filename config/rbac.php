<?php

return [
    'permissions' => [
        'dashboard.view' => ['Dashboard', 'dashboard'],
        'users.view' => ['Melihat pengguna', 'users'],
        'users.create' => ['Membuat pengguna', 'users'],
        'users.update' => ['Mengubah pengguna', 'users'],
        'users.status' => ['Mengubah status pengguna', 'users'],
        'roles.assign' => ['Menetapkan role dan scope', 'users'],
        'roles.manage' => ['Mengelola role dan permission', 'users'],
        'masters.view' => ['Melihat data master', 'masters'],
        'masters.create' => ['Membuat data master', 'masters'],
        'masters.update' => ['Mengubah data master', 'masters'],
        'masters.status' => ['Mengubah status data master', 'masters'],
        'audit-logs.view' => ['Melihat audit log', 'audit'],
    ],

    'roles' => [
        'super-admin' => ['Super Admin', ['*']],
        'admin-kordik' => ['Admin Tim Kordik', ['dashboard.view', 'users.view', 'users.create', 'users.update', 'users.status', 'roles.assign', 'masters.view', 'masters.create', 'masters.update', 'masters.status', 'audit-logs.view']],
        'tim-kordik' => ['Tim Kordik / Penanggung Jawab', ['dashboard.view', 'users.view', 'masters.view', 'audit-logs.view']],
        'sekretariat-ksm' => ['Sekretariat KSM', ['dashboard.view', 'masters.view']],
        'ketua-ksm' => ['Ketua / Koordinator KSM', ['dashboard.view', 'masters.view']],
        'pembimbing' => ['Pembimbing / Penguji', ['dashboard.view']],
        'supervisor' => ['Supervisor', ['dashboard.view']],
        'peserta' => ['Peserta Didik', ['dashboard.view']],
    ],
];
