//! Pairing codes (§8.4).
//!
//! A short code, shown on the relay's screen and typed into a candidate
//! machine while a member of staff is standing there. It is not a password and
//! does not need to survive an attacker: it is valid only while `relay pair` is
//! running, in one room, with the person who started it watching. What it
//! protects against is the machine next door pairing itself by accident, not a
//! determined adversary — that is the certificate's job (§8.3).

/// Characters a person cannot misread across a room.
///
/// No `0`/`O`, no `1`/`I`/`L`, no `5`/`S`, no `8`/`B`. An invigilator reading a
/// code aloud to a lab assistant is the actual interface here, and every
/// ambiguous glyph is a failed pairing somebody has to diagnose.
const ALPHABET: &[u8] = b"ACDEFGHJKMNPQRTUVWXYZ2346799";

/// A fresh pairing code, `ABCD-EFGH`.
pub fn code() -> String {
    let mut bytes = [0u8; 8];
    getrandom::getrandom(&mut bytes).expect("the OS always has randomness");

    let letters: String = bytes
        .iter()
        .map(|byte| ALPHABET[*byte as usize % ALPHABET.len()] as char)
        .collect();

    format!("{}-{}", &letters[..4], &letters[4..])
}

/// Normalise a code as typed: case and punctuation are presentation.
pub fn normalise(input: &str) -> String {
    input
        .chars()
        .filter(|c| c.is_ascii_alphanumeric())
        .flat_map(|c| c.to_uppercase())
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_code_is_readable_across_a_room() {
        let code = code();

        assert_eq!(code.len(), 9, "{code}");
        assert_eq!(code.chars().nth(4), Some('-'));

        for c in code.chars().filter(|c| *c != '-') {
            assert!(
                ALPHABET.contains(&(c as u8)),
                "`{c}` is not in the unambiguous alphabet"
            );
        }
    }

    #[test]
    fn codes_do_not_repeat() {
        // Not a cryptographic claim — see the module note — but a code that
        // repeated across runs would let yesterday's code pair today's room.
        let codes: std::collections::HashSet<String> = (0..200).map(|_| code()).collect();

        assert!(codes.len() > 190, "only {} distinct codes in 200", codes.len());
    }

    #[test]
    fn a_code_is_compared_the_way_it_is_typed() {
        let code = code();

        assert_eq!(normalise(&code), normalise(&code.to_lowercase()));
        assert_eq!(normalise(&code), normalise(&code.replace('-', "")));
        assert_eq!(normalise(&code), normalise(&format!(" {code} ")));
    }

    #[test]
    fn a_different_code_stays_different() {
        assert_ne!(normalise("ACDE-FGHJ"), normalise("ACDE-FGHK"));
    }
}
