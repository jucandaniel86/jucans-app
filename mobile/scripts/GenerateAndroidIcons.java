import java.awt.Color;
import java.awt.Graphics2D;
import java.awt.RenderingHints;
import java.awt.geom.Ellipse2D;
import java.awt.image.BufferedImage;
import java.nio.file.Files;
import java.nio.file.Path;
import javax.imageio.ImageIO;

// Run from mobile/: java -Djava.awt.headless=true scripts/GenerateAndroidIcons.java
class GenerateAndroidIcons {
    public static void main(String[] args) throws Exception {
        Path source = Path.of("src/assets/jucans_logo.png");
        BufferedImage logo = ImageIO.read(source.toFile());
        if (logo == null) throw new IllegalArgumentException("Cannot read " + source);

        // Keep all visible artwork within Android's central 66dp adaptive-icon safe circle.
        double radius = 0;
        double centerX = logo.getWidth() / 2.0;
        double centerY = logo.getHeight() / 2.0;
        for (int y = 0; y < logo.getHeight(); y++) {
            for (int x = 0; x < logo.getWidth(); x++) {
                if ((logo.getRGB(x, y) >>> 24) != 0) {
                    radius = Math.max(radius, Math.hypot(x - centerX, y - centerY));
                }
            }
        }
        if (radius == 0) throw new IllegalArgumentException("Logo is empty");

        String[] densities = {"mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi"};
        double[] scales = {1, 1.5, 2, 3, 4};
        for (int i = 0; i < densities.length; i++) {
            double density = scales[i];
            Path directory = Path.of("android/app/src/main/res/mipmap-" + densities[i]);
            Files.createDirectories(directory);
            writeIcon(logo, radius, (int) (108 * density), 32 * density, false, false,
                directory.resolve("ic_launcher_foreground.png"));
            writeIcon(logo, radius, (int) (48 * density), 21 * density, true, false,
                directory.resolve("ic_launcher.png"));
            writeIcon(logo, radius, (int) (48 * density), 21 * density, true, true,
                directory.resolve("ic_launcher_round.png"));
        }
        System.out.println("Generated 15 Android launcher PNGs from " + source);
    }

    private static void writeIcon(BufferedImage logo, double sourceRadius, int size,
            double targetRadius, boolean background, boolean round, Path output) throws Exception {
        // Render at 4x resolution before downsampling for clean edges at mdpi.
        int resolution = size * 4;
        BufferedImage image = new BufferedImage(resolution, resolution, BufferedImage.TYPE_INT_ARGB);
        Graphics2D graphics = image.createGraphics();
        graphics.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON);
        graphics.setRenderingHint(RenderingHints.KEY_INTERPOLATION, RenderingHints.VALUE_INTERPOLATION_BICUBIC);
        if (background) {
            graphics.setColor(Color.WHITE);
            if (round) graphics.fill(new Ellipse2D.Double(0, 0, resolution, resolution));
            else graphics.fillRect(0, 0, resolution, resolution);
        }
        double factor = targetRadius * 4 / sourceRadius;
        int width = (int) Math.round(logo.getWidth() * factor);
        int height = (int) Math.round(logo.getHeight() * factor);
        graphics.drawImage(logo, (resolution - width) / 2, (resolution - height) / 2, width, height, null);
        graphics.dispose();
        BufferedImage result = new BufferedImage(size, size, BufferedImage.TYPE_INT_ARGB);
        graphics = result.createGraphics();
        graphics.setRenderingHint(RenderingHints.KEY_INTERPOLATION, RenderingHints.VALUE_INTERPOLATION_BICUBIC);
        graphics.drawImage(image, 0, 0, size, size, null);
        graphics.dispose();
        ImageIO.write(result, "png", output.toFile());
    }
}
