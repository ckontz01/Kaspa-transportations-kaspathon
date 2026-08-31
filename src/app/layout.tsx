import type { Metadata } from "next";
import { JetBrains_Mono, Plus_Jakarta_Sans } from "next/font/google";
import "leaflet/dist/leaflet.css";
import "../../OSRH_KASPA_PHP/assets/css/main.css";
import "../../OSRH_KASPA_PHP/assets/css/layout.css";
import "../../OSRH_KASPA_PHP/assets/css/components.css";
import "../../OSRH_KASPA_PHP/assets/css/maps.css";
import "../../OSRH_KASPA_PHP/assets/css/responsive.css";
import "./legacy-inline.css";
import "../../tokens.css";
import "./globals.css";
import { AppShell } from "@/components/app-shell";
import { OsrhProvider } from "@/components/osrh-provider";

const productFont = Plus_Jakarta_Sans({
  subsets: ["latin"],
  variable: "--font-jakarta",
  weight: ["400", "500", "600", "700", "800"],
  display: "swap",
});

const dataFont = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-jetbrains",
  weight: ["400", "500", "600"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "OSRH — Kaspa Ridehailing",
  description:
    "Kaspa-powered ride-hailing for passengers, drivers, and mobility operators in Cyprus.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" className={`${productFont.variable} ${dataFont.variable}`}>
      <body className="theme-hum osrh-v3">
        <OsrhProvider>
          <AppShell>{children}</AppShell>
        </OsrhProvider>
      </body>
    </html>
  );
}
