from oryntra_agent.agent.media import classify_attachment
from oryntra_agent.api.schemas import MediaAttachment


def test_classify_audio_opus() -> None:
    assert (
        classify_attachment(MediaAttachment(content_type="audio/opus", file_type="audio"))
        == "audio"
    )


def test_classify_image_jpeg() -> None:
    assert (
        classify_attachment(MediaAttachment(content_type="image/jpeg", file_type="image"))
        == "image"
    )


def test_classify_video_mp4() -> None:
    assert (
        classify_attachment(MediaAttachment(content_type="video/mp4", file_type="video")) == "video"
    )


def test_classify_pdf_as_document() -> None:
    assert (
        classify_attachment(MediaAttachment(content_type="application/pdf", file_type="file"))
        == "document"
    )


def test_classify_unknown_falls_to_other() -> None:
    assert (
        classify_attachment(MediaAttachment(content_type="something/weird", file_type=None))
        == "other"
    )


def test_classify_uses_file_type_fallback() -> None:
    assert classify_attachment(MediaAttachment(content_type=None, file_type="audio")) == "audio"
