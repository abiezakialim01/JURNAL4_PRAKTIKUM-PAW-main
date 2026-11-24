<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if (!Auth::user()->isMahasiswa()) {
            abort(403, 'Akses ditolak. Pengumpulan tugas hanya dapat dilakukan oleh Mahasiswa.');
        }

        $user = Auth::user();

        $enrollment = $assignment->course->students()->where('users.id', $user->id)->first();
        if (!$enrollment || $enrollment->pivot->status !== 'approved') {
            abort(403, 'Akses ditolak. Anda belum disetujui oleh Dosen untuk mengikuti mata kuliah tugas ini.');
        }

        if ($assignment->isOverdue()) {
            return back()->with('error', 'Gagal mengumpulkan: Batas waktu pengumpulan (deadline) tugas telah berakhir.');
        }

        $validated = $request->validate([
            'jawaban' => ['required', 'string', 'min:10', 'max:20000'],
        ], [
            'jawaban.required' => 'Teks jawaban tugas wajib diisi.',
            'jawaban.min' => 'Jawaban terlalu pendek. Tuliskan jawaban minimal 10 karakter.',
            'jawaban.max' => 'Jawaban melebihi batas maksimal 20.000 karakter.',
        ]);

        Submission::updateOrCreate(
            [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ],
            [
                'jawaban' => $validated['jawaban'],
                'submitted_at' => now(),
            ]
        );

        return back()->with('success', 'Jawaban tugas Anda berhasil disimpan ke sistem.');
    }

    public function grade(Request $request, Submission $submission): RedirectResponse
    {
        // TODO 5a (grade): Cek apakah dosen adalah pengampu mata kuliah, validasi nilai (0-100) & catatan_dosen, lalu update submission.
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if ($submission->assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah ini.');
        }

        $validated = $request->validate([
            'nilai' => ['required', 'integer', 'min:0', 'max:100'],
            'catatan_dosen' => ['nullable', 'string', 'max:1000'],
        ], [
            'nilai.required' => 'Nilai wajib diisi.',
            'nilai.integer' => 'Nilai harus berupa angka bulat.',
            'nilai.min' => 'Nilai minimal adalah 0.',
            'nilai.max' => 'Nilai maksimal adalah 100.',
        ]);

        $submission->update([
            'nilai' => $validated['nilai'],
            'catatan_dosen' => $validated['catatan_dosen'] ?? null,
        ]);

        return back()->with('success', "Nilai untuk mahasiswa {$submission->user->name} berhasil disimpan.");
    }

    public function exportCsv(Assignment $assignment): StreamedResponse
    {
        // TODO 5b (exportCsv): Cek kepemilikan dosen, lalu gunakan response()->streamDownload() dan fputcsv() untuk mengunduh data nilai mahasiswa.
        if (!Auth::check()) {
            abort(403, 'Silakan login terlebih dahulu.');
        }

        if ($assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Akses ditolak. Anda bukan dosen pengampu mata kuliah ini.');
        }

        $assignment->load([
            'course.approvedStudents' => fn($q) => $q->orderBy('name'),
            'submissions',
        ]);

        $submissionsByUser = $assignment->submissions->keyBy('user_id');
        $fileName = 'rekap_nilai_' . Str::slug($assignment->course->kode_mk . '_' . $assignment->judul, '_') . '.csv';

        return response()->streamDownload(function () use ($assignment, $submissionsByUser) {
            $handle = fopen('php://output', 'w');

            // Header CSV
            fputcsv($handle, [
                'No',
                'NIM',
                'Nama Mahasiswa',
                'Email',
                'Status Pengumpulan',
                'Waktu Pengumpulan',
                'Nilai',
                'Status Kelulusan',
                'Catatan Dosen',
            ]);

            $no = 1;
            foreach ($assignment->course->approvedStudents as $student) {
                $submission = $submissionsByUser->get($student->id);

                fputcsv($handle, [
                    $no++,
                    $student->nomor_induk ?? '-',
                    $student->name,
                    $student->email,
                    $submission ? 'Sudah Mengumpulkan' : 'Belum Mengumpulkan',
                    $submission && $submission->submitted_at ? $submission->submitted_at->format('Y-m-d H:i:s') : '-',
                    $submission && !is_null($submission->nilai) ? $submission->nilai : '-',
                    $submission ? $submission->status_nilai : 'Belum Mengumpulkan',
                    $submission->catatan_dosen ?? '-',
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}