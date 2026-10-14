# WPU SEO

Gratis plugin från [wordpressutveckling.se](https://wordpressutveckling.se/). Koden förklaras rad för rad i guiden: **[https://wordpressutveckling.se/bygg-ett-eget-seo-plugin/](https://wordpressutveckling.se/bygg-ett-eget-seo-plugin/)**

Byggt av [Kristoffer Pihlfelt](https://pihlfelt.se) på [Pihlfelt & Vänner](https://pihlfelt.se). Licens: GPL-2.0-or-later.

- **Bidragsgivare:** Pihlfelt & Vänner
- **Kräver WordPress:** 6.7
- **Kräver PHP:** 8.2
- **Version:** 1.0.0
- **Licens:** GPL-2.0-or-later

## Vad det gör

Ett litet SEO-plugin, samma som körs på wordpressutveckling.se. Panelen "SEO" i redigeraren ger varje inlägg och sida:

* SEO-titel (tomt = rubriken och sajtens namn)
* Metabeskrivning (tomt = utdraget, annars början av texten)
* Delningsbild för Open Graph (tomt = utvald bild)
* Dölj för sökmotorer (noindex, och sidan tas bort ur webbplatskartan)

Kategorier, produktkategorier och andra taxonomier får fälten SEO-titel och metabeskrivning när du redigerar dem (tom beskrivning = kategorins egen beskrivning). WooCommerce produkter får panelen som andra inlägg, och butikssidan använder fälten på sidan Butik.

I sidhuvudet skrivs metabeskrivning, Open Graph-taggar och twitter:card.

Under Inställningar > SEO finns de globala valen: standardbild för delning, standardbeskrivning, konto på X (twitter:site), vilka innehållstyper som får SEO-panelen och vilka taxonomier som får SEO-fält. Inga analyser och inga poäng.

Är Yoast SEO, Rank Math, SEOPress, All in One SEO eller The SEO Framework aktivt gör pluginet ingenting, så att sidhuvudet aldrig får dubbla taggar.

## För utvecklare

* wpu_seo_post_types – vilka posttyper som får panelen.
* wpu_seo_taxonomies – vilka taxonomier som får SEO-fält.
* wpu_seo_default_image – ersätter standardbilden från inställningarna (bilage-ID eller URL).

## Installation

Tillägg > Lägg till nytt > Ladda upp tillägg, välj zip-filen och aktivera. Fyll i panelen "SEO" i sidopanelen när du redigerar.

## Källa

Koden förklaras i guiden "Bygg ett eget SEO-plugin":
https://wordpressutveckling.se/bygg-ett-eget-seo-plugin/

Senaste versionen: https://wordpressutveckling.se/ladda-ner/wpu-seo/

## Ändringslogg

### 1.0.0
* Första versionen.
