//! Static assets, compiled into the binary.
//!
//! §16 is emphatic about why: the web app vendors KaTeX rather than using a CDN
//! because "an exam hall may have no internet, and a paper whose equations
//! render as raw TeX is not a paper anyone can sit". The relay is the same
//! argument taken further — there is no network in the room *at all*, so an
//! asset that is not inside this executable does not exist on exam morning.
//!
//! `include_bytes!` rather than a directory on disk, for the same reason the
//! content key is never written down: a file the relay reads at runtime is a
//! file that can be missing, moved, or half-copied by an installer. A paper
//! that renders without its fonts because someone zipped the folder wrongly is
//! a failure discovered by a candidate, in a room, with a clock running.
//!
//! Fonts are **woff2 only**. KaTeX's stylesheet lists woff2 first and browsers
//! stop at the first format they support, so the woff and ttf fallbacks are
//! dead weight for WebView2 and every browser a lab machine will have — 1.2 MB
//! of it, against §17's installer budget.

use axum::http::{header, StatusCode};
use axum::response::{IntoResponse, Response};

/// The KaTeX release vendored here.
///
/// Kept in step with `web/package.json`, and stated in one place so a mismatch
/// is visible rather than inferred. The candidate client mirrors
/// `web/src/components/RichContent.tsx`; if that file's KaTeX moves and this
/// does not, the same paper renders two ways.
pub const KATEX_VERSION: &str = "0.16.47";

const KATEX_CSS: &[u8] = include_bytes!("../assets/katex/katex.min.css");
const KATEX_JS: &[u8] = include_bytes!("../assets/katex/katex.min.js");

/// Every font KaTeX's stylesheet can ask for, by the exact basename in the
/// `url(fonts/…)` rules. Listed explicitly because `include_bytes!` needs a
/// literal path — and because a missing entry then fails the build rather than
/// serving a 404 to a candidate mid-equation.
const FONTS: &[(&str, &[u8])] = &[
    ("KaTeX_AMS-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_AMS-Regular.woff2")),
    ("KaTeX_Caligraphic-Bold.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Caligraphic-Bold.woff2")),
    ("KaTeX_Caligraphic-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Caligraphic-Regular.woff2")),
    ("KaTeX_Fraktur-Bold.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Fraktur-Bold.woff2")),
    ("KaTeX_Fraktur-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Fraktur-Regular.woff2")),
    ("KaTeX_Main-Bold.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Main-Bold.woff2")),
    ("KaTeX_Main-BoldItalic.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Main-BoldItalic.woff2")),
    ("KaTeX_Main-Italic.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Main-Italic.woff2")),
    ("KaTeX_Main-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Main-Regular.woff2")),
    ("KaTeX_Math-BoldItalic.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Math-BoldItalic.woff2")),
    ("KaTeX_Math-Italic.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Math-Italic.woff2")),
    ("KaTeX_SansSerif-Bold.woff2", include_bytes!("../assets/katex/fonts/KaTeX_SansSerif-Bold.woff2")),
    ("KaTeX_SansSerif-Italic.woff2", include_bytes!("../assets/katex/fonts/KaTeX_SansSerif-Italic.woff2")),
    ("KaTeX_SansSerif-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_SansSerif-Regular.woff2")),
    ("KaTeX_Script-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Script-Regular.woff2")),
    ("KaTeX_Size1-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Size1-Regular.woff2")),
    ("KaTeX_Size2-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Size2-Regular.woff2")),
    ("KaTeX_Size3-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Size3-Regular.woff2")),
    ("KaTeX_Size4-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Size4-Regular.woff2")),
    ("KaTeX_Typewriter-Regular.woff2", include_bytes!("../assets/katex/fonts/KaTeX_Typewriter-Regular.woff2")),
];

/// Cache hard. These bytes are immutable for the life of the binary, and a lab
/// machine re-fetching 543 KB of fonts on every question would be spending the
/// one resource the room is short of.
const IMMUTABLE: &str = "public, max-age=31536000, immutable";

fn asset(content_type: &'static str, body: &'static [u8]) -> Response {
    (
        StatusCode::OK,
        [(header::CONTENT_TYPE, content_type), (header::CACHE_CONTROL, IMMUTABLE)],
        body,
    )
        .into_response()
}

pub async fn katex_css() -> Response {
    asset("text/css; charset=utf-8", KATEX_CSS)
}

pub async fn katex_js() -> Response {
    asset("text/javascript; charset=utf-8", KATEX_JS)
}

/// A KaTeX font, by basename.
///
/// The path is matched against the fixed list above rather than joined onto a
/// directory, so there is no traversal to get wrong: a name that is not one of
/// the twenty is a 404, not a read.
pub async fn katex_font(axum::extract::Path(name): axum::extract::Path<String>) -> Response {
    match FONTS.iter().find(|(font, _)| *font == name) {
        Some((_, body)) => asset("font/woff2", body),
        None => (StatusCode::NOT_FOUND, "no such font").into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_font_the_stylesheet_asks_for_is_compiled_in() {
        // The failure this prevents is silent and lands in an exam: a stylesheet
        // rule pointing at a font nobody vendored renders that glyph range in a
        // fallback face, so an integral sign or a large brace quietly becomes
        // something else halfway through a maths paper.
        let css = std::str::from_utf8(KATEX_CSS).expect("stylesheet is utf-8");

        let mut wanted: Vec<&str> = css
            .match_indices("fonts/")
            .map(|(at, _)| {
                let rest = &css[at + "fonts/".len()..];
                let end = rest.find(')').unwrap_or(rest.len());
                rest[..end].trim_end_matches(|c| c == '"' || c == '\'')
            })
            .filter(|name| name.ends_with(".woff2"))
            .collect();

        wanted.sort_unstable();
        wanted.dedup();

        assert!(!wanted.is_empty(), "parsed no font references out of the stylesheet");

        for name in wanted {
            assert!(
                FONTS.iter().any(|(font, _)| *font == name),
                "katex.min.css asks for `{name}`, which is not compiled into the binary"
            );
        }
    }

    #[test]
    fn no_font_is_empty() {
        for (name, body) in FONTS {
            assert!(!body.is_empty(), "`{name}` vendored as zero bytes");
        }
    }

    #[test]
    fn a_font_name_cannot_walk_out_of_the_list() {
        for attempt in ["../../../etc/passwd", "..\\config.json", "KaTeX_Main-Regular.ttf"] {
            assert!(
                !FONTS.iter().any(|(font, _)| *font == attempt),
                "`{attempt}` resolved to a vendored asset"
            );
        }
    }
}
