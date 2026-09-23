<?php require_once( 'couch/cms.php' ); ?>
<cms:template title='Home'>
    <cms:editable name='page_heading' label='Page heading' type='text' order='1'>Your new CouchCMS site</cms:editable>

    <cms:editable name='main_content' label='Main content' type='richtext' order='2'>
        <p>This page is a CouchCMS template. The heading above it and this
        paragraph are editable regions: they live in the database and can be
        changed from the admin panel, without touching a file.</p>

        <p>The file that produced them is <code>index.php</code> in the root of
        your site. Everything CouchCMS itself needs lives in
        <code>couch/</code> next to it.</p>
    </cms:editable>
</cms:template>
<!DOCTYPE html>
<!--
    This is the starter template PanelAlpha laid down beside couch/.

    CouchCMS is not a CMS you log into and get a website from: it is a CMS you
    bolt onto pages you wrote yourself. A page becomes editable by requiring
    couch/cms.php at the top, calling COUCH::invoke() at the bottom, and
    declaring the parts that should be editable in the block above -- which
    prints nothing where it stands, so the regions can be shown wherever the
    layout wants them.

    (Nothing in this comment may spell one of those tags out in full: CouchCMS's
    parser reads HTML comments like any other text, so a tag named here would be
    a tag it then looks for a closing half of.)

    Edit this file over SFTP to change the layout; edit the regions from the
    admin panel at /couch/ to change the words. Replace it entirely when you are
    ready -- nothing in couch/ depends on it.

    The "Powered by CouchCMS" link in the footer is added by CouchCMS itself,
    and it is a licence condition rather than a decoration: see section 100 of
    couch/config.php.
-->
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><cms:show page_heading /></title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #fbfbfa;
            color: #24221e;
        }
        .wrap { max-width: 42rem; margin: 0 auto; padding: 4rem 1.5rem 3rem; }
        h1 { font-size: 2rem; line-height: 1.2; margin: 0 0 1.5rem; }
        .content { font-size: 1.05rem; }
        .content p { margin: 0 0 1rem; }
        code { background: #efece4; padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.95em; }
        a { color: #8a5a1f; }
        @media (prefers-color-scheme: dark) {
            body { background: #16161a; color: #e8e6e1; }
            code { background: #2a2a2f; }
            a { color: #e0a95f; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1><cms:show page_heading /></h1>

        <div class="content">
            <cms:show main_content />
        </div>
    </div>
</body>
</html>
<?php COUCH::invoke(); ?>
