<div class="page-head">
    <div>
        <h1>系统公告 <span class="sub">发布给全部或指定租户</span></h1>
    </div>
    <div class="head-actions">
        <button class="btn admin" type="button">发布公告</button>
    </div>
</div>

<section class="grid two-col">
    <div class="panel">
        <div class="panel-head"><span>发布公告</span><span class="sub">开发版表单</span></div>
        <div class="panel-body">
            <div class="grid" style="grid-template-columns: 1fr 1fr;">
                <label class="fg"><span>公告类型</span><select><option>维护</option><option>新功能</option><option>通知</option></select></label>
                <label class="fg"><span>可见范围</span><select><option>全部租户</option><option>指定租户</option></select></label>
            </div>
            <label class="fg"><span>标题</span><input value="批量导入功能更新"></label>
            <label class="fg"><span>内容</span><textarea>请各租户管理员在导入订单后确认货源地预判结果。</textarea></label>
            <button class="btn admin" type="button">保存草稿</button>
        </div>
    </div>
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
