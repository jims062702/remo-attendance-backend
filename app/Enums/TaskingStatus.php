<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Tasking status, filed per shift. A tasker may select several.
 *
 * From the operational note: "In cases where CBs are disabled or not present,
 * the Support team should file their attendance to ensure that all attendance
 * is properly recorded on a day-to-day basis." These values therefore cover
 * both productive work and every reason work did not happen -- an empty queue,
 * a disabled account, a power cut -- because an unexplained blank day is the
 * thing the operation is trying to eliminate.
 *
 * Stored in a pivot table rather than as a set column so the list can grow
 * without an ALTER on a large table, and so filtering by one status is indexed.
 */
enum TaskingStatus: string
{
    use HasEnumValues;

    // ---- Productive
    case Tasking = 'tasking';
    case Training = 'training';

    // ---- Course completion, queue empty
    case PrimaryCourseCompletedEmptyQueue = 'primary_course_completed_empty_queue';
    case SecondaryCourseCompletedEmptyQueue = 'secondary_course_completed_empty_queue';
    case TertiaryCourseCompletedEmptyQueue = 'tertiary_course_completed_empty_queue';
    case AllCoursesCompletedEmptyQueue = 'all_courses_completed_empty_queue';
    case CoursePendingEmptyQueue = 'course_pending_empty_queue';

    // ---- Present but blocked
    case PresentEmptyThrottling = 'present_empty_throttling';
    case BmLinterIssues = 'bm_linter_issues';
    case PresentDisabledBm = 'present_disabled_bm';
    case PresentDisabledQuality = 'present_disabled_quality';
    case PresentProjectManagementError = 'present_project_management_error';
    case PowerInterruption = 'power_interruption';

    // ---- Absent
    case Absent = 'absent';
    case AbsentAccountIssue = 'absent_account_issue';
    case AbsentEmptyQueue = 'absent_empty_queue';
    case AbsentDisabledBm = 'absent_disabled_bm';
    case AbsentDisabledQuality = 'absent_disabled_quality';
    case AbsentProjectManagementError = 'absent_project_management_error';

    // ---- Discontinued
    case DiscontinuedPersonalIssue = 'discontinued_personal_issue';
    case DiscontinuedPayIssue = 'discontinued_pay_issue';
    case DiscontinuedDisabledBm = 'discontinued_disabled_bm';
    case DiscontinuedQualityIssue = 'discontinued_quality_issue';

    // ---- Waiting
    case EqWaitingForWtAssignment = 'eq_waiting_for_wt_assignment';

    public function label(): string
    {
        return match ($this) {
            self::Tasking => 'Tasking',
            self::Training => 'Training',
            self::PrimaryCourseCompletedEmptyQueue => 'Primary Course Completed – Empty Queue',
            self::SecondaryCourseCompletedEmptyQueue => 'Secondary Course Completed – Empty Queue',
            self::TertiaryCourseCompletedEmptyQueue => 'Tertiary Course Completed – Empty Queue',
            self::AllCoursesCompletedEmptyQueue => 'All Courses Completed (Primary, Secondary & Tertiary) – Empty Queue',
            self::CoursePendingEmptyQueue => 'Course Pending – Empty Queue',
            self::PresentEmptyThrottling => 'Present: Empty – due to throttling after completing a few tasks.',
            self::BmLinterIssues => 'BM Linter Issues',
            self::PresentDisabledBm => 'Present Disabled (BM)',
            self::PresentDisabledQuality => 'Present Disabled (Quality)',
            self::PresentProjectManagementError => 'Present - Project management initiated (error)',
            self::PowerInterruption => 'Power interruption',
            self::Absent => 'Absent',
            self::AbsentAccountIssue => 'Absent - Account Issue',
            self::AbsentEmptyQueue => 'Absent Empty Queue',
            self::AbsentDisabledBm => 'Absent Disabled (BM)',
            self::AbsentDisabledQuality => 'Absent Disabled (Quality)',
            self::AbsentProjectManagementError => 'Absent - Project management initiated (error)',
            self::DiscontinuedPersonalIssue => 'Discontinued - Personal Issue',
            self::DiscontinuedPayIssue => 'Discontinued - Pay Issue',
            self::DiscontinuedDisabledBm => 'Discontinued - Disabled (BM)',
            self::DiscontinuedQualityIssue => 'Discontinued - Quality Issue',
            self::EqWaitingForWtAssignment => 'EQ - waiting for WT assignment',
        };
    }

    /**
     * Grouping for the picker, so 24 options are not one flat wall of text.
     */
    public function group(): string
    {
        return match ($this) {
            self::Tasking, self::Training => 'Working',

            self::PrimaryCourseCompletedEmptyQueue,
            self::SecondaryCourseCompletedEmptyQueue,
            self::TertiaryCourseCompletedEmptyQueue,
            self::AllCoursesCompletedEmptyQueue,
            self::CoursePendingEmptyQueue,
            self::EqWaitingForWtAssignment => 'Empty queue',

            self::PresentEmptyThrottling,
            self::BmLinterIssues,
            self::PresentDisabledBm,
            self::PresentDisabledQuality,
            self::PresentProjectManagementError,
            self::PowerInterruption => 'Present but blocked',

            self::Absent,
            self::AbsentAccountIssue,
            self::AbsentEmptyQueue,
            self::AbsentDisabledBm,
            self::AbsentDisabledQuality,
            self::AbsentProjectManagementError => 'Absent',

            self::DiscontinuedPersonalIssue,
            self::DiscontinuedPayIssue,
            self::DiscontinuedDisabledBm,
            self::DiscontinuedQualityIssue => 'Discontinued',
        };
    }

    /**
     * Whether this status means the tasker actually produced work.
     */
    public function isProductive(): bool
    {
        return in_array($this, [self::Tasking, self::Training], true);
    }

    /**
     * Options grouped for a <select> or checkbox list.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $case) {
            $grouped[$case->group()][] = ['value' => $case->value, 'label' => $case->label()];
        }

        return $grouped;
    }
}
