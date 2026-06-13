@extends('layouts.student')

@section('title', 'Không gian học tập | LearnForge')

@section('content')
    <section class="student-hero">
        <div class="student-hero-content">
            <p class="student-eyebrow">
                <span class="student-eyebrow-dot"></span>
                Không gian học tập của {{ auth()->user()->name }}
            </p>
            <h1>Tiếp tục hành trình học tập</h1>
            <p class="student-hero-copy">
                Quay lại bài học gần nhất, theo dõi tiến độ và nhận hỗ trợ từ AI Tutor khi bạn cần.
            </p>
            <div class="student-hero-actions">
                <a class="student-button" href="#student-courses">
                    Tiếp tục học
                    <span aria-hidden="true">→</span>
                </a>
                <a class="student-button is-light" href="#student-ai-tutor">Hỏi AI Tutor</a>
            </div>
        </div>

        <div class="student-hero-visual" aria-hidden="true">
            <div class="student-focus-card">
                <div class="student-focus-top">
                    <span class="student-focus-label">Tiếp tục bài học</span>
                    <span class="student-live-badge">Đang học</span>
                </div>
                <h2>Giao tiếp tiếng Hàn thực chiến</h2>
                <p>Bài 08 · Hội thoại tại nơi làm việc</p>
                <div class="student-focus-progress">
                    <div class="student-progress-meta">
                        <span>Tiến độ khoá học</span>
                        <span>72%</span>
                    </div>
                    <div class="student-progress-track">
                        <div class="student-progress-fill is-72"></div>
                    </div>
                </div>
            </div>

            <div class="student-ai-orbit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path d="M12 3 14 8.5 19.5 10 14 12l-2 5.5-2-5.5-5.5-2L10 8.5 12 3Z"></path>
                    <path d="m18.5 15 .8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2Z"></path>
                </svg>
            </div>
        </div>
    </section>

    <section class="student-stat-grid" aria-label="Tổng quan học tập">
        <article class="student-card student-stat-card">
            <span class="student-stat-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Z"></path>
                    <path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H20"></path>
                </svg>
            </span>
            <div>
                <p class="student-stat-value">03</p>
                <p class="student-stat-label">Khoá học đang học</p>
            </div>
        </article>

        <article class="student-card student-stat-card">
            <span class="student-stat-icon is-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M4 19V9M10 19V5M16 19v-7M22 19V3"></path>
                </svg>
            </span>
            <div>
                <p class="student-stat-value">68%</p>
                <p class="student-stat-label">Tiến độ trung bình</p>
            </div>
        </article>

        <article class="student-card student-stat-card">
            <span class="student-stat-icon is-orange">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5l3 2"></path>
                </svg>
            </span>
            <div>
                <p class="student-stat-value">02</p>
                <p class="student-stat-label">Bài kiểm tra sắp tới</p>
            </div>
        </article>

        <article class="student-card student-stat-card">
            <span class="student-stat-icon is-blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M8 4h8M9 2h6v4H9zM6 5h12a2 2 0 0 1 2 2v14H4V7a2 2 0 0 1 2-2Z"></path>
                    <path d="m8 13 2.5 2.5L16 10"></path>
                </svg>
            </span>
            <div>
                <p class="student-stat-value">12</p>
                <p class="student-stat-label">Bài học đã hoàn thành</p>
            </div>
        </article>
    </section>

    <div class="student-dashboard-grid">
        <section class="student-card student-section" id="student-courses">
            <div class="student-section-header">
                <div>
                    <h2 class="student-section-title">Khoá học đang học</h2>
                    <p class="student-section-copy">Tiếp tục từ đúng nơi bạn đã dừng lại.</p>
                </div>
                <a class="student-text-link" href="#">Xem tất cả</a>
            </div>

            <div class="student-course-list">
                <article class="student-course-card">
                    <div class="student-course-cover">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5v-16Z"></path>
                            <path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H20"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="student-course-heading">
                            <h3 class="student-course-title">Giao tiếp tiếng Hàn thực chiến</h3>
                            <span class="student-badge">Đang học</span>
                        </div>
                        <p class="student-course-meta">8/12 bài học · Cập nhật hôm nay</p>
                        <div class="student-course-progress">
                            <div class="student-progress-track">
                                <div class="student-progress-fill is-72"></div>
                            </div>
                            <span>72%</span>
                        </div>
                    </div>
                    <a class="student-button is-outline is-small" href="#">Học tiếp</a>
                </article>

                <article class="student-course-card">
                    <div class="student-course-cover is-green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M5 4h14v16H5z"></path>
                            <path d="M8 8h8M8 12h5M8 16h7"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="student-course-heading">
                            <h3 class="student-course-title">Ngữ pháp TOPIK II nền tảng</h3>
                            <span class="student-badge is-green">Ổn định</span>
                        </div>
                        <p class="student-course-meta">6/14 bài học · Học 2 ngày trước</p>
                        <div class="student-course-progress">
                            <div class="student-progress-track">
                                <div class="student-progress-fill is-58"></div>
                            </div>
                            <span>58%</span>
                        </div>
                    </div>
                    <a class="student-button is-outline is-small" href="#">Học tiếp</a>
                </article>

                <article class="student-course-card">
                    <div class="student-course-cover is-orange">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path d="M12 3v18M4.5 7.5h15M6 3h12l2 4.5-2 4.5H6L4 7.5 6 3Z"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="student-course-heading">
                            <h3 class="student-course-title">Từ vựng theo chủ đề</h3>
                            <span class="student-badge">Mới</span>
                        </div>
                        <p class="student-course-meta">4/10 bài học · Học 4 ngày trước</p>
                        <div class="student-course-progress">
                            <div class="student-progress-track">
                                <div class="student-progress-fill is-42"></div>
                            </div>
                            <span>42%</span>
                        </div>
                    </div>
                    <a class="student-button is-outline is-small" href="#">Học tiếp</a>
                </article>
            </div>
        </section>

        <section class="student-card student-section">
            <div class="student-section-header">
                <div>
                    <h2 class="student-section-title">Lịch học sắp tới</h2>
                    <p class="student-section-copy">Lớp học và hạn bài gần nhất.</p>
                </div>
                <a class="student-text-link" href="#">Lịch đầy đủ</a>
            </div>

            <div class="student-schedule-list">
                <article class="student-schedule-item">
                    <time class="student-schedule-date" datetime="2026-06-15">
                        <strong>15</strong>
                        <span>Thg 6</span>
                    </time>
                    <div>
                        <h3>Live class: Phản xạ hội thoại</h3>
                        <p>19:30 · Phòng học trực tuyến</p>
                    </div>
                </article>
                <article class="student-schedule-item">
                    <time class="student-schedule-date" datetime="2026-06-17">
                        <strong>17</strong>
                        <span>Thg 6</span>
                    </time>
                    <div>
                        <h3>Kiểm tra ngữ pháp chương 4</h3>
                        <p>Hạn nộp 21:00 · 30 phút</p>
                    </div>
                </article>
                <article class="student-schedule-item">
                    <time class="student-schedule-date" datetime="2026-06-20">
                        <strong>20</strong>
                        <span>Thg 6</span>
                    </time>
                    <div>
                        <h3>Workshop luyện nghe TOPIK</h3>
                        <p>09:00 · Có bản ghi sau buổi học</p>
                    </div>
                </article>
            </div>
        </section>
    </div>

    <section class="student-card student-ai-card" id="student-ai-tutor">
        <div class="student-ai-content">
            <p class="student-eyebrow">
                <span class="student-eyebrow-dot"></span>
                LearnForge AI Tutor
            </p>
            <h2>Một người bạn học luôn sẵn sàng</h2>
            <p>
                Hỏi lại kiến thức chưa hiểu, luyện tập theo trình độ hoặc nhận gợi ý ôn tập dựa trên
                tiến độ hiện tại. AI Tutor hỗ trợ bạn học chủ động hơn trong từng bài học.
            </p>
            <a class="student-button is-light" href="#">Bắt đầu hỏi AI</a>
        </div>
        <div class="student-ai-prompt">
            <span class="student-ai-prompt-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path d="M12 3 14 8.5 19.5 10 14 12l-2 5.5-2-5.5-5.5-2L10 8.5 12 3Z"></path>
                    <path d="m18.5 15 .8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2Z"></path>
                </svg>
            </span>
            <div>
                <span>Gợi ý câu hỏi</span>
                <strong>“Giải thích giúp mình sự khác nhau giữa 은/는 và 이/가?”</strong>
            </div>
        </div>
    </section>

    <div class="student-insight-grid">
        <section class="student-card student-section">
            <div class="student-section-header">
                <div>
                    <h2 class="student-section-title">Gợi ý ôn tập hôm nay</h2>
                    <p class="student-section-copy">Ưu tiên từ kết quả học gần đây của bạn.</p>
                </div>
            </div>
            <div class="student-recommendation-list">
                <article class="student-recommendation">
                    <span class="student-recommendation-number">01</span>
                    <div>
                        <h3>Ôn lại 18 từ vựng chủ đề công việc</h3>
                        <p>Khoảng 10 phút · Độ ưu tiên cao</p>
                    </div>
                </article>
                <article class="student-recommendation">
                    <span class="student-recommendation-number">02</span>
                    <div>
                        <h3>Luyện nghe đoạn hội thoại bài 7</h3>
                        <p>Khoảng 8 phút · AI đề xuất</p>
                    </div>
                </article>
                <article class="student-recommendation">
                    <span class="student-recommendation-number">03</span>
                    <div>
                        <h3>Làm lại 5 câu ngữ pháp đã sai</h3>
                        <p>Khoảng 12 phút · Củng cố kiến thức</p>
                    </div>
                </article>
            </div>
        </section>

        <section class="student-card student-achievement">
            <div class="student-achievement-copy">
                <p class="student-eyebrow student-achievement-label">Thành tích tuần</p>
                <h2>Bạn đang giữ nhịp học rất tốt!</h2>
                <p>Đã hoàn thành 82% mục tiêu tuần và duy trì chuỗi học tập 6 ngày liên tiếp.</p>
            </div>
            <div class="student-achievement-ring" aria-label="Hoàn thành 82 phần trăm mục tiêu tuần">
                <div class="student-achievement-value">
                    <strong>82%</strong>
                    <span>Mục tiêu tuần</span>
                </div>
            </div>
        </section>
    </div>
@endsection
