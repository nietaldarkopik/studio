import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Ternyata Begini Studio",
  description: "Ruang produksi video kreatif Ternyata Begini.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="id">
      <body>{children}</body>
    </html>
  );
}
