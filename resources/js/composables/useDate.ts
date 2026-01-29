import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import isToday from 'dayjs/plugin/isToday';
import isYesterday from 'dayjs/plugin/isYesterday';
import calendar from 'dayjs/plugin/calendar';
import localizedFormat from 'dayjs/plugin/localizedFormat';

// Extend dayjs with plugins
dayjs.extend(relativeTime);
dayjs.extend(isToday);
dayjs.extend(isYesterday);
dayjs.extend(calendar);
dayjs.extend(localizedFormat);

export function useDate() {
    /**
     * Format a date using dayjs
     */
    function formatDate(date: string | Date, format = 'MMM D, YYYY'): string {
        return dayjs(date).format(format);
    }

    /**
     * Format a date with time
     */
    function formatDateTime(date: string | Date, format = 'MMM D, YYYY h:mm A'): string {
        return dayjs(date).format(format);
    }

    /**
     * Get relative time (e.g., "2 hours ago")
     */
    function fromNow(date: string | Date): string {
        return dayjs(date).fromNow();
    }

    /**
     * Get calendar time (e.g., "Today at 2:30 PM", "Yesterday at 1:00 PM")
     */
    function calendarTime(date: string | Date): string {
        return dayjs(date).calendar(null, {
            sameDay: '[Today at] h:mm A',
            lastDay: '[Yesterday at] h:mm A',
            lastWeek: 'dddd [at] h:mm A',
            sameElse: 'MMM D, YYYY [at] h:mm A',
        });
    }

    /**
     * Check if date is today
     */
    function isDateToday(date: string | Date): boolean {
        return dayjs(date).isToday();
    }

    /**
     * Check if date is yesterday
     */
    function isDateYesterday(date: string | Date): boolean {
        return dayjs(date).isYesterday();
    }

    /**
     * Get smart date label (Today, Yesterday, or formatted date)
     */
    function smartDate(date: string | Date): string {
        const d = dayjs(date);
        if (d.isToday()) return 'Today';
        if (d.isYesterday()) return 'Yesterday';
        return d.format('MMM D, YYYY');
    }

    /**
     * Format time only
     */
    function formatTime(date: string | Date, format = 'h:mm A'): string {
        return dayjs(date).format(format);
    }

    /**
     * Get the difference in days
     */
    function diffInDays(date1: string | Date, date2: string | Date = new Date()): number {
        return dayjs(date2).diff(dayjs(date1), 'day');
    }

    /**
     * Get start of day
     */
    function startOfDay(date: string | Date = new Date()): Date {
        return dayjs(date).startOf('day').toDate();
    }

    /**
     * Get end of day
     */
    function endOfDay(date: string | Date = new Date()): Date {
        return dayjs(date).endOf('day').toDate();
    }

    /**
     * Subtract days from a date
     */
    function subtractDays(days: number, from: string | Date = new Date()): Date {
        return dayjs(from).subtract(days, 'day').toDate();
    }

    return {
        dayjs,
        formatDate,
        formatDateTime,
        fromNow,
        calendarTime,
        isDateToday,
        isDateYesterday,
        smartDate,
        formatTime,
        diffInDays,
        startOfDay,
        endOfDay,
        subtractDays,
    };
}
