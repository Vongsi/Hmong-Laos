<div style="font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;color:#171A2E">
    <h2 style="margin:0 0 12px">{{ $subjectLine }}</h2>
    @foreach ($lines as $line)
        <p style="margin:0 0 8px">{{ $line }}</p>
    @endforeach
    <p style="margin:12px 0 0"><a href="{{ $url }}" style="display:inline-block;background:#171A2E;color:#fff;padding:10px 16px;border-radius:999px;font-weight:700;text-decoration:none">{{ $urlLabel }}</a></p>
</div>
