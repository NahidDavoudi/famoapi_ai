<?php

namespace App\Modules\Public;

use App\Core\AssetUrl;
use App\Core\Cache;

class PublicService
{
    private const GROUP = 'public';
    private const TTL   = 600;

    public function getCourses(): array
    {
        $courses = Course::findAllPublished();
        foreach ($courses as &$course) {
            $features = Course::getFeatures((int) $course['id']);
            $course['features'] = array_map(fn($f) => $f['feature_text'], $features);
            $course['badge_label'] = $course['badge_label'] ?? null;
            $course['target_grades'] = $course['target_grades'] ?? null;
            $course['format'] = $course['format'] ?? null;
            $course['full_description'] = $course['full_description'] ?? null;
        }
        unset($course);

        Cache::forget(self::GROUP, 'courses');
        Cache::forget(self::GROUP, 'http_response:/api/v1/public/courses');

        return ['courses' => $courses];
    }

    public function getInstructors(): array
    {
        $instructors = Instructor::findAllPublished();
        foreach ($instructors as &$instructor) {
            $instructor['social_links'] = Instructor::getSocialLinks((int) $instructor['id']);
            if (array_key_exists('image_url', $instructor)) {
                $instructor['image_url'] = AssetUrl::fromStorage($instructor['image_url']);
            }
        }
        unset($instructor);

        Cache::forget(self::GROUP, 'instructors');
        Cache::forget(self::GROUP, 'http_response:/api/v1/public/instructors');

        return ['instructors' => $instructors];
    }

    public function getSupporters(): array
    {
        // خواندن مستقیم آخرین وضعیت پشتیبان‌ها از دیتابیس و پاک‌سازی کش قدیمی
        // تا هر تغییر از پنل ادمین یا دیتابیس بلافاصله در لندینگ پیج نمایش داده شود
        $supporters = Supporter::findAllPublished();

        Cache::forget(self::GROUP, 'supporters');
        Cache::forget(self::GROUP, 'http_response:/api/v1/public/supporters');

        foreach ($supporters as &$supporter) {
            $resolvedUrl = AssetUrl::fromStorage($supporter['avatar'] ?? null);
            $supporter['avatar_url'] = $resolvedUrl;
            $supporter['image_url'] = $resolvedUrl;
            $supporter['description'] = $supporter['description'] ?? null;
        }
        unset($supporter);

        return ['supporters' => $supporters];
    }
}