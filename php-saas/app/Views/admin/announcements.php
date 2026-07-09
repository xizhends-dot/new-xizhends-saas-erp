<div class="page-head">
    <div>
        <h1>系统公告 <span class="sub">发布给全部或指定租户</span></h1>
    </div>
</div>

<section class="grid">
    <div class="panel">
        <div class="panel-head"><span>已发布公告</span><span class="sub"><?= e(count($announcements)) ?> 条</span></div>
        <div class="panel-body">
            <?php foreach ($announcements as $announcement): ?>
                <div class="announce">
                    <div class="announce-title"><?= e($announcement['title']) ?></div>
                    <div class="announce-meta"><?= e($announcement['kind']) ?> · <?= e($announcement['scope']) ?> · <?= e($announcement['date']) ?></div>
                    <div><?= e($announcement['body']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
