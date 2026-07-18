<?php

declare(strict_types=1);

namespace App\Http\Controllers\Director;

use App\Http\Controllers\Controller;
use App\Models\ClearanceRecord;
use App\Models\College;
use App\Support\CaseMonths;
use App\Support\MedicalCaseSummary;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable "Summary of Medical Cases" (FR-ANL-06 / FR-ANL-03) — a
 * one-month reproduction of the clinic's official monthly report, reached
 * the same way as the nurse's clearance print (FR-NRS-05): the Analytics
 * page loads this standalone document into a hidden iframe and calls
 * window.print() on it. No side effects — a plain, month-scoped view of
 * the matrix, so it can be previewed and reprinted freely.
 *
 * The numbers come from the SAME MedicalCaseSummary aggregation the
 * on-screen matrix uses, month-scoped to visits checked in that month
 * (FR-ANL-07: encoded, categorized cases only). Faculty and NASA are not
 * tracked by HealthPass (students only), so the paper form's FACULTY/NASA
 * columns are intentionally omitted — the 12 college columns remain.
 */
class CaseSummaryPrintController extends Controller
{
    /** FR-ANL-03 fixed column order (also the matrix's). */
    private const MATRIX_COLLEGE_ORDER = [
        'COE', 'CEA', 'CBS', 'CAS', 'CSSP', 'CCS',
        'CHTM', 'CIT', 'LAW', 'GS', 'SHS', 'LHS',
    ];

    /**
     * The example-symptom lists printed under each category name — static
     * template text reproduced from the official form (keyed by the exact
     * ClearanceRecord::CASE_CATEGORIES strings), not system data.
     */
    private const CATEGORY_SYMPTOMS = [
        'Alimentary System' => 'Nausea, Vomiting, Hyperacidity, Heartburn, Dyspepsia, Infectious Diarrhea, Constipation',
        'Respiratory System' => 'Cough, Colds, Fever, Bronchial Asthma, Difficulty of Breathing, Pneumonia',
        'Musculo-Skeletal System' => 'Osteoarthritis, Osteochondritis, Muscle and Joint Spasm, Sprain, Strain, Fracture, Dislocation',
        'Integumentary System' => 'Burns, Cuts, Abrasion, Laceration, Bruise, Puncture, Minor Surgery, Skin Diseases, Allergies, Infected Wound, Animal Bite',
        'Urinary System' => 'UTI',
        'Metabolic Endocrine System' => 'Diabetes, Hyperthyroidism, Hypothyroidism, Dyslipidemia',
        'Cardiovascular System' => 'Chest pain, Hypertension, Hypotension, Arrhythmias, Bradycardia, Tachycardia',
        'Eyes, Ears, Nose & Throat Disorders' => 'Sty, Fungal or Bacterial Infection, Foreign body, Vertigo, Otitis Media/Externa, Sinusitis, Epistaxis, Pharyngitis, Laryngitis, Tonsillitis, Rhinitis',
    ];

    public function __invoke(Request $request): View
    {
        // Same month rule as the Analytics dashboard (CaseMonths), so a
        // printed report always matches the month shown on screen.
        $month = CaseMonths::resolve($request->query('month'));

        $summary = MedicalCaseSummary::build($month);

        // Columns in the FR-ANL-03 fixed order (all 12, zero-case included).
        $matrixOrder = array_flip(self::MATRIX_COLLEGE_ORDER);
        $colleges = College::orderBy('code')->get()
            ->sortBy(fn (College $college) => $matrixOrder[$college->code] ?? PHP_INT_MAX)
            ->values();

        return view('director.summary-print', [
            'monthLabel' => strtoupper($month->format('F Y')),
            'colleges' => $colleges,
            'categories' => ClearanceRecord::CASE_CATEGORIES,
            'symptoms' => self::CATEGORY_SYMPTOMS,
            'counts' => $summary->counts,
            'totals' => $summary->totals,
            'categoryTotals' => $summary->categoryTotals,
            'grandTotal' => $summary->grandTotal,
        ]);
    }
}
