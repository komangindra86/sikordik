<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Wording shown to people. Stored status codes stay as they are; only the label changes.
 */
class Ui
{
    /** code => [label, tone] where tone is one of muted, wait, info, ok, bad. */
    public const STATUS = [
        'placement' => [
            'draft' => ['Draf', 'muted'],
            'menunggu_konfirmasi_ksm' => ['Menunggu KSM', 'wait'],
            'diterima_ksm' => ['Diterima KSM', 'wait'],
            'menunggu_persetujuan_kordik' => ['Menunggu Tim Kordik', 'wait'],
            'menunggu_dokumen' => ['Melengkapi dokumen', 'wait'],
            'terverifikasi' => ['Siap dijadwalkan', 'info'],
            'dijadwalkan' => ['Terjadwal', 'info'],
            'sedang_stase' => ['Sedang stase', 'ok'],
            'menunggu_penyelesaian' => ['Menunggu penyelesaian', 'wait'],
            'selesai' => ['Selesai', 'ok'],
            'ditolak_ksm' => ['Ditolak KSM', 'bad'],
            'ditolak_kordik' => ['Ditolak Tim Kordik', 'bad'],
            'dibatalkan' => ['Dibatalkan', 'bad'],
        ],
        'document' => [
            'pending' => ['Belum diperiksa', 'wait'],
            'valid' => ['Valid', 'ok'],
            'rejected' => ['Perlu perbaikan', 'bad'],
            'exception' => ['Dikecualikan', 'info'],
        ],
        'scan' => [
            'pending' => ['Menunggu pemeriksaan keamanan', 'wait'],
            'clean' => ['Aman', 'ok'],
            'held' => ['Tertahan', 'bad'],
            'infected' => ['Ditolak pemindai', 'bad'],
            'invalid' => ['Berkas rusak', 'bad'],
        ],
        'schedule' => [
            'draft' => ['Draf', 'muted'],
            'submitted' => ['Menunggu pembimbing', 'wait'],
            'revision' => ['Perlu revisi', 'bad'],
            'approved' => ['Disetujui, belum terbit', 'wait'],
            'published' => ['Terbit', 'ok'],
            'completed' => ['Selesai', 'ok'],
            'cancelled' => ['Dibatalkan', 'muted'],
            'superseded' => ['Digantikan', 'muted'],
        ],
        'assignment' => [
            'pending' => ['Menunggu KSM', 'wait'],
            'approved' => ['Disetujui', 'ok'],
            'rejected' => ['Ditolak', 'bad'],
            'replaced' => ['Digantikan', 'muted'],
        ],
        'attendance' => [
            'draft' => ['Draf', 'muted'],
            'waiting' => ['Menunggu verifikasi', 'wait'],
            'corrected' => ['Dikoreksi, menunggu verifikasi', 'wait'],
            'verified' => ['Terverifikasi', 'ok'],
            'rejected' => ['Ditolak', 'bad'],
        ],
        'logbook' => [
            'draft' => ['Draf', 'muted'],
            'submitted' => ['Menunggu pemeriksaan', 'wait'],
            'revision' => ['Perlu revisi', 'bad'],
            'approved' => ['Disetujui', 'ok'],
            'rejected' => ['Ditolak', 'bad'],
            'locked' => ['Dikunci', 'ok'],
        ],
        'assessment' => [
            'draft' => ['Draf', 'muted'],
            'approved' => ['Disahkan, belum dipublikasikan', 'wait'],
            'published' => ['Dipublikasikan', 'ok'],
        ],
        'appeal' => [
            'submitted' => ['Keberatan diajukan', 'wait'],
            'reviewing' => ['Keberatan ditinjau', 'wait'],
            'accepted' => ['Keberatan diterima', 'info'],
            'rejected' => ['Keberatan ditolak', 'muted'],
            'completed' => ['Koreksi selesai', 'ok'],
        ],
        'survey' => [
            '' => ['Belum dimulai', 'muted'],
            'issued' => ['Kode sudah terbit', 'info'],
            'submitted' => ['Menunggu pemeriksaan Admin', 'wait'],
            'verified' => ['Terverifikasi', 'ok'],
            'rejected' => ['Belum cocok, ajukan ulang', 'bad'],
        ],
        'request' => [
            'pending' => ['Menunggu keputusan', 'wait'],
            'pending_ksm' => ['Menunggu KSM', 'wait'],
            'pending_kordik' => ['Menunggu Tim Kordik', 'wait'],
            'approved' => ['Disetujui', 'ok'],
            'completed' => ['Disetujui', 'ok'],
            'executed' => ['Dilaksanakan', 'ok'],
            'rejected' => ['Ditolak', 'bad'],
            'withdrawn' => ['Ditarik', 'muted'],
        ],
        'summary' => [
            'draft' => ['Menunggu pengesahan Ketua KSM', 'wait'],
            'sealed' => ['Disahkan dan dikunci', 'ok'],
            'superseded' => ['Tidak berlaku', 'muted'],
        ],
        'presence' => [
            'hadir' => ['Hadir', 'ok'],
            'terlambat' => ['Terlambat', 'wait'],
            'izin' => ['Izin', 'info'],
            'sakit' => ['Sakit', 'info'],
            'tidak_hadir' => ['Tidak hadir', 'bad'],
        ],
    ];

    /** History wording for placement_histories.action and scheduling_histories.event. */
    public const EVENTS = [
        'created' => 'Penempatan dibuat', 'submit' => 'Diajukan ke KSM', 'ksm_accept' => 'KSM menerima', 'ksm_reject' => 'KSM menolak',
        'forward_to_kordik' => 'Diteruskan ke Tim Kordik', 'kordik_accept' => 'Tim Kordik menyetujui penerimaan', 'kordik_reject' => 'Tim Kordik menolak penerimaan',
        'verify' => 'Dokumen dinyatakan lengkap', 'revise' => 'Dikembalikan ke draf', 'cancel' => 'Penempatan dibatalkan', 'period_revised' => 'Periode / KSM direvisi',
        'exception_requested' => 'Pengecualian periode paralel diajukan', 'exception_approved' => 'Pengecualian periode paralel disetujui', 'exception_rejected' => 'Pengecualian periode paralel ditolak',
        'document_valid' => 'Dokumen dinyatakan valid', 'document_rejected' => 'Dokumen ditolak', 'document_exception' => 'Dokumen dikecualikan',
        'schedule_published' => 'Jadwal pertama terbit', 'activity_started' => 'Stase dimulai', 'period_extended' => 'Stase diperpanjang',
        'completion_submit' => 'Penyelesaian diajukan', 'completion_approve' => 'Permohonan disetujui', 'completion_reject' => 'Permohonan ditolak', 'completion_withdraw' => 'Permohonan ditarik',
        'completion_reopen_request' => 'Pembukaan kembali diajukan', 'completion_reopen_execute' => 'Penempatan dibuka kembali', 'completion_archive' => 'Diarsipkan',
        'attendance_draft' => 'Presensi disimpan sebagai draf', 'attendance_submit' => 'Presensi diajukan', 'attendance_correct' => 'Presensi dikoreksi Admin', 'attendance_verify' => 'Presensi diverifikasi',
        'attendance_reject' => 'Presensi ditolak', 'attendance_verifier_replaced' => 'Verifikator diganti', 'attendance_summary_generated' => 'Rekap dibuat',
        'attendance_summary_sealed' => 'Rekap disahkan Ketua KSM', 'attendance_summary_superseded' => 'Rekap lama tidak berlaku',
    ];

    public static function event(string $code): string
    {
        [$base, $detail] = array_pad(explode(':', $code, 2), 2, null);

        return (self::EVENTS[$base] ?? ucfirst(str_replace('_', ' ', $base))).($detail ? ' ('.$detail.')' : '');
    }

    public static function label(string $kind, ?string $code): string
    {
        return self::STATUS[$kind][$code ?? ''][0] ?? ucfirst(str_replace('_', ' ', (string) $code));
    }

    public static function tone(string $kind, ?string $code): string
    {
        return self::STATUS[$kind][$code ?? ''][1] ?? 'muted';
    }

    public static function date(?string $value): string
    {
        return $value ? Carbon::parse($value)->format('d-m-Y') : '—';
    }

    public static function dateTime(?string $value): string
    {
        return $value ? Carbon::parse($value)->format('d-m-Y H:i') : '—';
    }

    public static function period(?string $start, ?string $end): string
    {
        return self::date($start).' s.d. '.self::date($end);
    }
}
